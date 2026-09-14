<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Sllhsmile\HyperfLog\Aspect\GuzzleLogAspect;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;
use Sllhsmile\HyperfLog\Support\StreamSnapshotter;

class GuzzleLogAspectTest extends TestCase
{
    /**
     * Hyperf CoroutineHandler 返回已完成 Promise 时，sdklog 回调仍必须执行。
     */
    public function testItLogsFulfilledPromiseBeforeSynchronousWaitReturns(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())
            ->method('info')
            ->with('sdklog', self::callback(static function (array $context): bool {
                return array_key_exists('request', $context) && ! array_key_exists('request_id', $context);
            }));

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true]]],
            'trace_log' => ['guzzle' => []],
        ]));
        $aspect = new GuzzleLogAspect($config, $writer, new RequestContext($config));
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);

        $stack = new HandlerStack(static fn () => Create::promiseFor(new Response(200)));
        $stack->push($middleware, 'trace_log_sdk');

        $response = $stack(new Request('GET', 'https://example.com'), [])->wait();

        self::assertInstanceOf(Response::class, $response);
    }

    public function testItLogsSeekableBodiesAndRestoresTheirOriginalPositions(): void
    {
        $requestBody = Utils::streamFor('request-body');
        $requestBody->seek(4);
        $responseBody = Utils::streamFor('{"ok":true}');
        $responseBody->seek(3);

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'sdklog',
            self::callback(static fn (array $context): bool =>
                $context['request']['body'] === 'request-body'
                && $context['response']['status_code'] === 200
                && $context['response']['body'] === '{"ok":true}'
                && is_float($context['duration_ms'])),
        );

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true, 'response_enabled' => true]]],
            'trace_log' => ['guzzle' => []],
        ]));
        $aspect = new GuzzleLogAspect(
            $config,
            $writer,
            new RequestContext($config),
            new StreamSnapshotter(),
        );
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);
        $stack = new HandlerStack(static fn () => Create::promiseFor(new Response(200, [], $responseBody)));
        $stack->push($middleware, 'trace_log_sdk');

        $stack(new Request('POST', 'https://example.com', [], $requestBody), [])->wait();

        self::assertSame(4, $requestBody->tell());
        self::assertSame(3, $responseBody->tell());
    }

    public function testItDoesNotConsumeNonSeekableRequestOrResponseBodies(): void
    {
        $requestBody = Utils::streamFor('request-secret');
        $requestBody->seek(2);
        $responseBody = Utils::streamFor('response-secret');
        $responseBody->seek(3);

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'sdklog',
            self::callback(static fn (array $context): bool =>
                $context['request']['body'] === null
                && $context['response']['body'] === null),
        );

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true, 'response_enabled' => true]]],
            'trace_log' => ['guzzle' => []],
        ]));
        $aspect = new GuzzleLogAspect(
            $config,
            $writer,
            new RequestContext($config),
            new StreamSnapshotter(),
        );
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);
        $stack = new HandlerStack(static fn () => Create::promiseFor(
            new Response(200, [], new NoSeekStream($responseBody)),
        ));
        $stack->push($middleware, 'trace_log_sdk');

        $stack(new Request('POST', 'https://example.com', [], new NoSeekStream($requestBody)), [])->wait();

        self::assertSame(2, $requestBody->tell());
        self::assertSame(3, $responseBody->tell());
    }

    /**
     * 已完成的失败 Promise 也必须记录 sdklog，并继续抛出原始异常。
     */
    public function testItLogsRejectedPromiseAndPreservesOriginalException(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())
            ->method('info')
            ->with('sdklog', self::callback(static function (array $context): bool {
                return array_key_exists('exception', $context) && ! array_key_exists('request_id', $context);
            }));

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true]]],
            'trace_log' => ['guzzle' => []],
        ]));
        $aspect = new GuzzleLogAspect($config, $writer, new RequestContext($config));
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);
        $exception = new \RuntimeException('connection failed');

        $stack = new HandlerStack(static fn () => Create::rejectionFor($exception));
        $stack->push($middleware, 'trace_log_sdk');

        $this->expectExceptionObject($exception);
        $stack(new Request('GET', 'https://example.com'), [])->wait();
    }

    /**
     * 日志 writer 发生异常时，不能改变成功请求的响应结果。
     */
    public function testLoggingFailureDoesNotRejectSuccessfulRequest(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->method('info')->willThrowException(new \RuntimeException('logger unavailable'));

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true]]],
            'trace_log' => ['guzzle' => []],
        ]));
        $aspect = new GuzzleLogAspect($config, $writer, new RequestContext($config));
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);

        $stack = new HandlerStack(static fn () => Create::promiseFor(new Response(200)));
        $stack->push($middleware, 'trace_log_sdk');

        $response = $stack(new Request('GET', 'https://example.com'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testItLogsSynchronousHandlerExceptionAndPreservesIt(): void
    {
        $exception = new \RuntimeException('handler failed');
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'sdklog',
            self::callback(static fn (array $context): bool =>
                $context['exception']['message'] === 'handler failed'),
        );

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true]]],
        ]));
        $aspect = new GuzzleLogAspect($config, $writer, new RequestContext($config));
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);
        $handler = $middleware(static function () use ($exception): never {
            throw $exception;
        });

        $this->expectExceptionObject($exception);
        $handler(new Request('GET', 'https://example.com'), []);
    }

    public function testItSkipsMiddlewareInjectionForCallableHandler(): void
    {
        $handler = static fn () => Create::promiseFor(new Response());
        $client = new Client(['handler' => $handler]);
        $config = new LogConfig(new Config([]));
        $aspect = new GuzzleLogAspect(
            $config,
            $this->createMock(LogWriter::class),
            new RequestContext($config),
        );
        $joinPoint = $this->createMock(ProceedingJoinPoint::class);
        $joinPoint->expects(self::once())->method('process')->willReturn(null);
        $joinPoint->expects(self::once())->method('getInstance')->willReturn($client);

        self::assertNull($aspect->process($joinPoint));
        self::assertSame($handler, $client->getConfig('handler'));
    }

    public function testItDoesNotDuplicateMiddlewareOnSharedHandlerStack(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with('sdklog', self::isType('array'));
        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true]]],
        ]));
        $aspect = new GuzzleLogAspect($config, $writer, new RequestContext($config));
        $stack = HandlerStack::create(static fn () => Create::promiseFor(new Response()));

        foreach ([new Client(['handler' => $stack]), new Client(['handler' => $stack])] as $client) {
            $joinPoint = $this->createMock(ProceedingJoinPoint::class);
            $joinPoint->method('process')->willReturn(null);
            $joinPoint->method('getInstance')->willReturn($client);
            $aspect->process($joinPoint);
        }

        $stack(new Request('GET', 'https://example.com'), [])->wait();
    }

    /**
     * 调用方即使设置其他 request-id，出站请求和 fallback 日志也必须沿用当前 trace。
     */
    public function testOutboundRequestAndFallbackUseCurrentTraceId(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->method('info')->willThrowException(new \RuntimeException('logger unavailable'));

        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['sdklog' => ['enabled' => true]]],
            'trace_log' => ['guzzle' => []],
        ]));
        $requestContext = new RequestContext($config);
        $requestContext->initializeTrace('context-123');
        $aspect = new GuzzleLogAspect($config, $writer, $requestContext);
        $method = new \ReflectionMethod($aspect, 'logMiddleware');
        $middleware = $method->invoke($aspect);

        $outboundRequest = null;
        $stack = new HandlerStack(static function (RequestInterface $request) use (&$outboundRequest) {
            $outboundRequest = $request;

            return Create::promiseFor(new Response(200));
        });
        $headerMethod = new \ReflectionMethod($aspect, 'pushRequestHeaderMiddleware');
        $headerMethod->invoke($aspect, $stack);
        $stack->push($middleware, 'trace_log_sdk');

        $errorLog = tempnam(sys_get_temp_dir(), 'hyperf-log-test-');
        self::assertNotFalse($errorLog);
        $previousErrorLog = ini_set('error_log', $errorLog);

        try {
            $stack(new Request('GET', 'https://example.com?token=query-secret', [
                'x-b3-traceid' => 'custom-456',
                'Authorization' => 'Bearer private-token',
            ], 'password=plain-secret'), [])->wait();

            self::assertInstanceOf(RequestInterface::class, $outboundRequest);
            self::assertSame('context-123', $outboundRequest->getHeaderLine('x-b3-traceid'));
            $fallbackLog = file_get_contents($errorLog);
            self::assertNotFalse($fallbackLog);
            self::assertStringContainsString('"request_id":"context-123"', $fallbackLog);
            self::assertStringNotContainsString('"request_id":"custom-456"', $fallbackLog);
            self::assertStringNotContainsString('private-token', $fallbackLog);
            self::assertStringNotContainsString('plain-secret', $fallbackLog);
            self::assertStringNotContainsString('query-secret', $fallbackLog);
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
            Context::destroy('x-b3-traceid');
            unlink($errorLog);
        }
    }

    /**
     * 调用方顶层 Guzzle 参数会同步为 CoroutineHandler 使用的 Swoole 配置。
     */
    public function testItMapsCallerGuzzleTimeoutOptionsToSwooleConfiguration(): void
    {
        $receivedOptions = [];
        $stack = HandlerStack::create(static function ($request, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;

            return Create::promiseFor(new Response());
        });
        $this->pushTimeoutMiddleware($stack, new LogConfig(new Config(['trace_log' => ['guzzle' => []]])));

        $stack(new Request('GET', 'https://example.com'), ['timeout' => 5, 'connect_timeout' => 2.5])->wait();

        self::assertSame(5, $receivedOptions['timeout']);
        self::assertSame(2.5, $receivedOptions['connect_timeout']);
        self::assertSame(5, $receivedOptions['swoole']['timeout']);
        self::assertSame(2.5, $receivedOptions['swoole']['connect_timeout']);
    }

    /**
     * 调用方和公共包均未提供超时时，不应创建 Swoole 配置。
     */
    public function testItLeavesSwooleTimeoutsUnsetWhenNoValueIsConfigured(): void
    {
        $receivedOptions = [];
        $stack = HandlerStack::create(static function ($request, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;

            return Create::promiseFor(new Response());
        });
        $this->pushTimeoutMiddleware($stack, new LogConfig(new Config(['trace_log' => ['guzzle' => []]])));

        $stack(new Request('GET', 'https://example.com'), [])->wait();

        self::assertArrayNotHasKey('swoole', $receivedOptions);
    }

    /**
     * 公共包只在调用方没有设置该项时注入其显式配置。
     */
    public function testItUsesConfiguredDefaults(): void
    {
        $receivedOptions = [];
        $stack = HandlerStack::create(static function ($request, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;

            return Create::promiseFor(new Response());
        });
        $this->pushTimeoutMiddleware($stack, new LogConfig(new Config([
            'trace_log' => ['guzzle' => ['timeout' => 10, 'connect_timeout' => 3]],
        ])));

        $stack(new Request('GET', 'https://example.com'), [])->wait();

        self::assertArrayNotHasKey('timeout', $receivedOptions);
        self::assertArrayNotHasKey('connect_timeout', $receivedOptions);
        self::assertSame(10.0, $receivedOptions['swoole']['timeout']);
        self::assertSame(3.0, $receivedOptions['swoole']['connect_timeout']);
    }

    /**
     * 调用方直接传入的 Swoole 超时配置需要覆盖顶层 Guzzle 参数和公共包默认值。
     */
    public function testItDoesNotOverrideCallerSwooleTimeoutOptions(): void
    {
        $receivedOptions = [];
        $stack = HandlerStack::create(static function ($request, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;

            return Create::promiseFor(new Response());
        });
        $this->pushTimeoutMiddleware($stack, new LogConfig(new Config([
            'trace_log' => ['guzzle' => ['timeout' => 10, 'connect_timeout' => 3]],
        ])));

        $stack(new Request('GET', 'https://example.com'), [
            'timeout' => 0,
            'connect_timeout' => 1,
            'swoole' => ['timeout' => 8, 'connect_timeout' => 4],
        ])->wait();

        self::assertSame(0, $receivedOptions['timeout']);
        self::assertSame(1, $receivedOptions['connect_timeout']);
        self::assertSame(8, $receivedOptions['swoole']['timeout']);
        self::assertSame(4, $receivedOptions['swoole']['connect_timeout']);
    }

    /**
     * 公共包未配置时，调用方的 Swoole 超时值仍需原样保留。
     */
    public function testItPreservesCallerSwooleTimeoutOptionsWithoutPackageDefaults(): void
    {
        $receivedOptions = [];
        $stack = HandlerStack::create(static function ($request, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;

            return Create::promiseFor(new Response());
        });
        $this->pushTimeoutMiddleware($stack, new LogConfig(new Config(['trace_log' => ['guzzle' => []]])));

        $stack(new Request('GET', 'https://example.com'), [
            'swoole' => ['timeout' => 8, 'connect_timeout' => 4],
        ])->wait();

        self::assertSame(8, $receivedOptions['swoole']['timeout']);
        self::assertSame(4, $receivedOptions['swoole']['connect_timeout']);
    }

    /**
     * @param HandlerStack $stack Guzzle 中间件栈
     */
    private function pushTimeoutMiddleware(HandlerStack $stack, LogConfig $config): void
    {
        $aspect = new GuzzleLogAspect(
            $config,
            (new \ReflectionClass(LogWriter::class))->newInstanceWithoutConstructor(),
            new RequestContext($config),
        );
        $method = new \ReflectionMethod($aspect, 'pushTimeoutMiddleware');
        $method->invoke($aspect, $stack);
    }
}

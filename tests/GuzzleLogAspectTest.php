<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Aspect\GuzzleLogAspect;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

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
            ->with('sdklog', self::arrayHasKey('request'));

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

    /**
     * 已完成的失败 Promise 也必须记录 sdklog，并继续抛出原始异常。
     */
    public function testItLogsRejectedPromiseAndPreservesOriginalException(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())
            ->method('info')
            ->with('sdklog', self::arrayHasKey('exception'));

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

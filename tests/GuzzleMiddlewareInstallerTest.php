<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\GuzzleMiddlewareInstaller;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogOrigin;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;
use Sllhsmile\HyperfLog\Support\SdkLogContextBuilder;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class GuzzleMiddlewareInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItPropagatesTracePreservesCallerOptionsAndLogsSuccess(): void
    {
        $seen = [];
        $stack = HandlerStack::create(static function (RequestInterface $request, array $options) use (&$seen) {
            $seen = [$request, $options];
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
        });
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Info,
            Collector::Sdk,
            self::callback(static fn(array $value): bool =>
                $value['request']['headers']['x-b3-traceid'] === ['trace-id']
                && $value['response'] === ['status_code' => 200]
                && isset($value['duration_ms'])),
            self::callback(static fn(LogOrigin $origin): bool => $origin->requestId === 'trace-id'),
        );
        $config = $this->config(true, false);
        $context = new RequestContext();
        $context->start('trace-id');
        $this->installer($config, $context, $logger)->install($stack);

        (new Client(['handler' => $stack]))->get('https://example.test', ['timeout' => 4]);

        $seenRequest = $seen[0] ?? null;
        $seenOptions = $seen[1] ?? null;
        self::assertInstanceOf(RequestInterface::class, $seenRequest);
        self::assertIsArray($seenOptions);
        self::assertSame('trace-id', $seenRequest->getHeaderLine('x-b3-traceid'));
        self::assertSame(4, $seenOptions['timeout']);
        self::assertArrayNotHasKey('swoole', $seenOptions);
    }

    public function testItLogsFailureAndPreservesOriginalException(): void
    {
        $error = new RuntimeException('network failed');
        $stack = HandlerStack::create(static fn() => Create::rejectionFor($error));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Error,
            Collector::Sdk,
            self::callback(static fn(array $value): bool =>
                $value['error']['type'] === RuntimeException::class && ! isset($value['response'])),
            self::isInstanceOf(LogOrigin::class),
        );
        $config = $this->config(true, true);
        $this->installer($config, new RequestContext(), $logger)->install($stack);

        try {
            (new Client(['handler' => $stack]))->get('https://example.test');
            self::fail('Expected request failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disabledResponseErrorStatuses')]
    public function testDisabledResponseStillMapsHttpErrorAndOmitsDetails(int $status, Level $level): void
    {
        $stack = HandlerStack::create(static fn() => Create::promiseFor(new Response($status, ['X-Private' => 'secret'], 'body')));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            $level,
            Collector::Sdk,
            self::callback(static fn(array $value): bool => $value['response'] === ['status_code' => $status]),
            self::isInstanceOf(LogOrigin::class),
        );
        $this->installer($this->config(true, false), new RequestContext(), $logger)->install($stack);

        (new Client(['handler' => $stack, 'http_errors' => false]))->get('https://example.test');
    }

    /** @return array<string, array{int, Level}> */
    public static function disabledResponseErrorStatuses(): array
    {
        return ['client error' => [404, Level::Warning], 'server error' => [503, Level::Error]];
    }

    public function testDisabledResponseDoesNotReadHeadersOrBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('getStatusCode')->willReturn(204);
        $response->expects(self::never())->method('getHeaders');
        $response->expects(self::never())->method('getBody');
        $config = $this->config(true, false);

        $context = (new SdkLogContextBuilder($config, new PayloadSnapshotter($config)))
            ->complete([], microtime(true), $response);

        self::assertSame(['status_code' => 204], $context['response']);
    }

    public function testPromiseCompletedInAnotherCoroutineKeepsRequestOrigin(): void
    {
        $pending = null;
        $stack = HandlerStack::create(static function () use (&$pending): Promise {
            return $pending = new Promise();
        });
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Info,
            Collector::Sdk,
            self::anything(),
            self::callback(static fn(LogOrigin $origin): bool => $origin->requestId === 'request-trace'),
        );
        $context = new RequestContext();
        $this->installer($this->config(true, false), $context, $logger)->install($stack);

        Coroutine\run(function () use ($stack, $context, &$pending): void {
            $context->start('request-trace');
            $promise = (new Client(['handler' => $stack]))->getAsync('https://example.test');
            self::assertInstanceOf(Promise::class, $pending);
            $completed = new Channel(1);
            Coroutine::create(function () use ($context, $pending, $completed): void {
                $context->start('callback-trace');
                $pending->resolve(new Response(200));
                PromiseUtils::queue()->run();
                $completed->push(true);
            });
            self::assertTrue($completed->pop(1));
            self::assertSame(200, $promise->wait()->getStatusCode());
        });
    }

    public function testDisabledCollectorStillPropagatesTraceWithoutLogging(): void
    {
        $seenId = null;
        $stack = HandlerStack::create(static function (RequestInterface $request) use (&$seenId) {
            $seenId = $request->getHeaderLine('x-b3-traceid');
            return Create::promiseFor(new Response());
        });
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $context = new RequestContext();
        $context->start('trace-id');
        $config = $this->config(false, false);
        $this->installer($config, $context, $logger)->install($stack);

        (new Client(['handler' => $stack]))->get('https://example.test');
        self::assertSame('trace-id', $seenId);
    }

    public function testInvalidPayloadConfigurationFailsBeforeMiddlewareInstallation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trace_log.payload.max_bytes');

        new LogConfig(new Config(['trace_log' => [
            'collectors' => ['sdk' => ['enabled' => true]],
            'payload' => ['max_bytes' => 0],
        ]]));
    }

    public function testSynchronousHandlerExceptionIsLoggedAndRethrownUnchanged(): void
    {
        $error = new RuntimeException('synchronous failure');
        $stack = HandlerStack::create(static function () use ($error): never {
            throw $error;
        });
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Error,
            Collector::Sdk,
            self::callback(static fn(array $value): bool => $value['error']['message'] === 'synchronous failure'),
            self::isInstanceOf(LogOrigin::class),
        );
        $this->installer($this->config(true, true), new RequestContext(), $logger)->install($stack);

        try {
            (new Client(['handler' => $stack]))->get('https://example.test');
            self::fail('Expected synchronous request failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testRepeatedInstallationLogsOnceAndRestoresResponseStream(): void
    {
        $response = new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}');
        $response->getBody()->seek(3);
        $stack = HandlerStack::create(static fn() => Create::promiseFor($response));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Info,
            Collector::Sdk,
            self::callback(static fn(array $value): bool =>
                $value['response']['body'] === '{"ok":true}'
                && $value['response']['status_code'] === 201),
            self::isInstanceOf(LogOrigin::class),
        );
        $installer = $this->installer($this->config(true, true), new RequestContext(), $logger);
        $installer->install($stack);
        $installer->install($stack);

        $received = (new Client(['handler' => $stack]))->get('https://example.test');

        self::assertSame($response, $received);
        self::assertSame(3, $response->getBody()->tell());
    }

    public function testLogWriteFailureDoesNotReplaceTheResponse(): void
    {
        $stack = HandlerStack::create(static fn() => Create::promiseFor(new Response(204)));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->method('log')->willThrowException(new RuntimeException('write failed'));
        $this->installer($this->config(true, true), new RequestContext(), $logger)->install($stack);

        self::assertSame(204, (new Client(['handler' => $stack]))->get('https://example.test')->getStatusCode());
    }

    private function config(bool $enabled, bool $response): LogConfig
    {
        return new LogConfig(new Config(['trace_log' => [
            'collectors' => ['sdk' => ['enabled' => $enabled, 'response_enabled' => $response]],
            'payload' => ['max_bytes' => 1024],
        ]]));
    }

    private function installer(
        LogConfig $config,
        RequestContext $context,
        CollectorLoggerInterface $logger,
    ): GuzzleMiddlewareInstaller {
        $snapshotter = new PayloadSnapshotter($config);

        return new GuzzleMiddlewareInstaller(
            $config,
            $context,
            new SdkLogContextBuilder($config, $snapshotter),
            $logger,
        );
    }
}

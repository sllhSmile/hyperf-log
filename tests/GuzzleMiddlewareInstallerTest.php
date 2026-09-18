<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\GuzzleMiddlewareInstaller;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;
use Sllhsmile\HyperfLog\Support\SdkLogContextBuilder;

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
        $logger->expects(self::once())->method('info')->with(
            Collector::Sdk,
            self::callback(static fn(array $value): bool =>
                $value['request']['headers']['x-b3-traceid'] === ['trace-id']
                && ! isset($value['response'])
                && isset($value['duration_ms'])),
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
        $logger->expects(self::once())->method('info')->with(
            Collector::Sdk,
            self::callback(static fn(array $value): bool => $value['error']['type'] === RuntimeException::class),
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

    public function testDisabledCollectorStillPropagatesTraceWithoutLogging(): void
    {
        $seenId = null;
        $stack = HandlerStack::create(static function (RequestInterface $request) use (&$seenId) {
            $seenId = $request->getHeaderLine('x-b3-traceid');
            return Create::promiseFor(new Response());
        });
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $context = new RequestContext();
        $context->start('trace-id');
        $config = $this->config(false, false);
        $this->installer($config, $context, $logger)->install($stack);

        (new Client(['handler' => $stack]))->get('https://example.test');
        self::assertSame('trace-id', $seenId);
    }

    public function testSnapshotFailureDoesNotAffectTheBusinessRequest(): void
    {
        $stack = HandlerStack::create(static fn() => Create::promiseFor(new Response(204)));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $config = new LogConfig(new Config(['trace_log' => [
            'collectors' => ['sdk' => ['enabled' => true]],
            'payload' => ['max_bytes' => 0],
        ]]));
        $this->installer($config, new RequestContext(), $logger)->install($stack);

        $response = (new Client(['handler' => $stack]))->get('https://example.test');
        self::assertSame(204, $response->getStatusCode());
    }

    public function testSynchronousHandlerExceptionIsLoggedAndRethrownUnchanged(): void
    {
        $error = new RuntimeException('synchronous failure');
        $stack = HandlerStack::create(static function () use ($error): never {
            throw $error;
        });
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Sdk,
            self::callback(static fn(array $value): bool => $value['error']['message'] === 'synchronous failure'),
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
        $logger->expects(self::once())->method('info')->with(
            Collector::Sdk,
            self::callback(static fn(array $value): bool =>
                $value['response']['body'] === '{"ok":true}'
                && $value['response']['status_code'] === 201),
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
        $logger->method('info')->willThrowException(new RuntimeException('write failed'));
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

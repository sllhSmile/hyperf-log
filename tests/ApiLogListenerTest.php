<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Event\RequestHandled;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Listener\ApiLogListener;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;

final class ApiLogListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItBuildsCompactHttpContextAndRestoresStreams(): void
    {
        $requestBody = Utils::streamFor('{"password":"secret"}');
        $requestBody->seek(4);
        $responseBody = Utils::streamFor('{"ok":true}');
        $responseBody->seek(2);
        $request = (new ServerRequest('POST', 'https://example.test/path', ['Content-Type' => 'application/json']))
            ->withBody($requestBody);
        $response = (new Response(201, ['Content-Type' => 'application/json']))->withBody($responseBody);
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Api,
            self::callback(static fn(array $value): bool =>
                $value['request']['body'] === '{"password":"secret"}'
                && $value['response']['body'] === '{"ok":true}'
                && ! isset($value['error'])),
        );

        $this->listener($logger)->process(new RequestHandled($request, $response));
        self::assertSame(4, $requestBody->tell());
        self::assertSame(2, $responseBody->tell());
    }

    public function testDisabledResponseIsEntirelyOmitted(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::never())->method('getContents');
        $stream->expects(self::never())->method('isSeekable');
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Api,
            self::callback(static fn(array $value): bool => ! array_key_exists('response', $value)),
        );
        $this->listener($logger, false)->process(new RequestHandled(
            new ServerRequest('GET', '/'),
            (new Response())->withBody($stream),
        ));
    }

    public function testNonRewindableBodyIsOmittedWithReasonAndNotConsumed(): void
    {
        $inner = Utils::streamFor('business-body');
        $inner->seek(3);
        $request = (new ServerRequest('POST', '/'))->withBody(new NoSeekStream($inner));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Api,
            self::callback(static fn(array $value): bool =>
                ! isset($value['request']['body'])
                && $value['payload_protection'][0]['reason'] === 'non_rewindable_stream'),
        );

        $this->listener($logger)->process(new RequestHandled($request, new Response()));
        self::assertSame(3, $inner->tell());
    }

    public function testItRecordsBusinessExceptionAndElapsedTime(): void
    {
        (new RequestContext())->start('api-trace');
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Api,
            self::callback(static fn(array $value): bool =>
                $value['error']['type'] === RuntimeException::class
                && $value['error']['code'] === 7
                && $value['duration_ms'] >= 0),
        );

        $this->listener($logger)->process(new RequestHandled(
            new ServerRequest('GET', '/'),
            new Response(500),
            new RuntimeException('business failed', 7),
        ));
    }

    public function testDisabledCollectorAndUnrelatedEventsDoNotLog(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $config = new LogConfig(new Config([]));
        $listener = new ApiLogListener($config, $logger, new RequestContext(), new PayloadSnapshotter($config));

        $listener->process(new \stdClass());
        $listener->process(new RequestHandled(new ServerRequest('GET', '/'), new Response()));
    }

    private function listener(CollectorLoggerInterface $logger, bool $response = true): ApiLogListener
    {
        $config = new LogConfig(new Config(['trace_log' => ['collectors' => [
            'api' => ['enabled' => true, 'response_enabled' => $response],
        ], 'payload' => ['max_bytes' => 1024]]]));

        return new ApiLogListener($config, $logger, new RequestContext(), new PayloadSnapshotter($config));
    }
}

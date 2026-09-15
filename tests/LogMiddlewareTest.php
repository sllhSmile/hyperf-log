<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sllhsmile\HyperfLog\Middleware\LogMiddleware;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\RequestContext;

class LogMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(ResponseInterface::class);
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItPreservesInboundRequestIdAndStartsCompleteTrace(): void
    {
        $config = new LogConfig(new Config(['trace_log' => []]));
        $requestContext = new RequestContext();
        $middleware = new LogMiddleware($config, $requestContext);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn ($request): bool => $request->getHeaderLine('x-b3-traceid') === 'upstream-id',
        ))->willReturn(new Response());

        $response = $middleware->process(
            (new Request('GET', '/'))->withHeader('x-b3-traceid', 'upstream-id'),
            $handler,
        );

        $trace = $requestContext->current();
        self::assertNotNull($trace);
        self::assertSame('upstream-id', $response->getHeaderLine('x-b3-traceid'));
        self::assertSame('upstream-id', $trace->requestId);
        self::assertGreaterThan(0, $trace->startedAt);
    }

    public function testItGeneratesMissingRequestIdAndPassesItToHandlerAndResponse(): void
    {
        $config = new LogConfig(new Config(['trace_log' => []]));
        $requestContext = new RequestContext();
        $middleware = new LogMiddleware($config, $requestContext);
        $handledRequestId = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function ($request) use (&$handledRequestId): ResponseInterface {
                $handledRequestId = $request->getHeaderLine('x-b3-traceid');

                return new Response();
            },
        );

        $response = $middleware->process(new Request('GET', '/'), $handler);

        self::assertNotSame('', $handledRequestId);
        self::assertSame($handledRequestId, $response->getHeaderLine('x-b3-traceid'));
        self::assertSame($handledRequestId, $requestContext->current()?->requestId);
    }

    public function testItAddsRequestIdToResponseContextBeforeExceptionEscapes(): void
    {
        ResponseContext::set(new Response());
        $config = new LogConfig(new Config(['trace_log' => []]));
        $middleware = new LogMiddleware($config, new RequestContext());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('request failed'));

        try {
            $middleware->process(
                (new Request('GET', '/'))->withHeader('x-b3-traceid', 'error-trace'),
                $handler,
            );
            self::fail('The request handler exception was not propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('request failed', $exception->getMessage());
        }

        self::assertSame('error-trace', ResponseContext::get()->getHeaderLine('x-b3-traceid'));
    }
}

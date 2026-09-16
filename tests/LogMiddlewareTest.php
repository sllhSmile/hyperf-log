<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Middleware\LogMiddleware;
use Sllhsmile\HyperfLog\Support\LogConfig;

final class LogMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItUsesInboundIdAndReturnsItToTheClient(): void
    {
        $context = new RequestContext();
        $middleware = new LogMiddleware(new LogConfig(new Config([])), $context);
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new Response();
            }
        };

        $response = $middleware->process(new ServerRequest('GET', '/', ['x-b3-traceid' => 'upstream']), $handler);

        self::assertSame('upstream', $context->id());
        self::assertSame('upstream', $response->getHeaderLine('x-b3-traceid'));
        self::assertSame('upstream', $handler->request?->getHeaderLine('x-b3-traceid'));
    }

    public function testItGeneratesAndInjectsAMissingId(): void
    {
        $context = new RequestContext();
        $middleware = new LogMiddleware(new LogConfig(new Config([])), $context);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['seen-id' => $request->getHeaderLine('x-b3-traceid')]);
            }
        };

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);
        self::assertNotSame('', $context->id());
        self::assertSame($context->id(), $response->getHeaderLine('seen-id'));
        self::assertSame($context->id(), $response->getHeaderLine('x-b3-traceid'));
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\RequestContext;

class RequestContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy('x-b3-traceid');
        Context::destroy('request_start_time');
    }

    public function testItPreservesInboundRequestId(): void
    {
        $config = new Config(['trace_log' => []]);
        $context = new RequestContext(new LogConfig($config));
        $request = (new Request('GET', '/'))->withHeader('x-b3-traceid', 'upstream-id');

        $result = $context->initialize($request);

        self::assertSame('upstream-id', $result->getHeaderLine('x-b3-traceid'));
        self::assertSame('upstream-id', $context->id());
        self::assertNotNull($context->startTime());
    }

    /**
     * 验证公共包会为未携带 request-id 的入站请求生成 ID 并写入请求对象。
     */
    public function testItGeneratesRequestIdWhenHeaderIsMissing(): void
    {
        $context = new RequestContext(new LogConfig(new Config(['trace_log' => []])));

        $result = $context->initialize(new Request('GET', '/'));

        self::assertNotSame('', $result->getHeaderLine('x-b3-traceid'));
        self::assertSame($result->getHeaderLine('x-b3-traceid'), $context->id());
    }

    /**
     * 空 Header 与缺失 Header 都不能作为有效的链路标识。
     */
    public function testItReplacesAnEmptyInboundRequestId(): void
    {
        $context = new RequestContext(new LogConfig(new Config(['trace_log' => []])));
        $request = (new Request('GET', '/'))->withHeader('x-b3-traceid', '  ');

        $result = $context->initialize($request);

        self::assertNotSame('', $result->getHeaderLine('x-b3-traceid'));
        self::assertSame($result->getHeaderLine('x-b3-traceid'), $context->id());
    }

    /**
     * 验证请求 header 名称和协程上下文键可由公共包独立配置覆盖。
     */
    public function testItUsesConfiguredRequestContextNames(): void
    {
        $context = new RequestContext(new LogConfig(new Config([
            'trace_log' => [
                'request_id_header' => 'trace-id',
                'request_id_context_key' => 'trace_id',
                'request_start_context_key' => 'trace_started_at',
            ],
        ])));

        $result = $context->initialize((new Request('GET', '/'))->withHeader('trace-id', 'configured-id'));

        self::assertSame('configured-id', $result->getHeaderLine('trace-id'));
        self::assertSame('configured-id', $context->id());
        self::assertNotNull($context->startTime());
        Context::destroy('trace_id');
        Context::destroy('trace_started_at');
    }

    /**
     * 宿主应用可以保留既有的 B3 Header 与 Context 键，确保新旧日志和响应共用同一 ID。
     */
    public function testItUsesExistingB3TraceConfiguration(): void
    {
        $context = new RequestContext(new LogConfig(new Config([
            'trace_log' => [
                'request_id_header' => 'x-b3-traceid',
                'request_id_context_key' => 'x-b3-traceid',
            ],
        ])));

        $result = $context->initialize((new Request('GET', '/'))->withHeader('x-b3-traceid', 'upstream-b3-id'));

        self::assertSame('upstream-b3-id', $result->getHeaderLine('x-b3-traceid'));
        self::assertSame('upstream-b3-id', $context->id());
        Context::destroy('x-b3-traceid');
        Context::destroy('request_start_time');
    }

    /**
     * 验证命令行和 RPC 可在无 HTTP 请求对象时初始化完整 trace 上下文。
     */
    public function testItInitializesTraceWithoutHttpRequest(): void
    {
        $context = new RequestContext(new LogConfig(new Config(['trace_log' => []])));

        $requestId = $context->initializeTrace();

        self::assertSame($requestId, $context->id());
        self::assertNotSame('', $requestId);
        self::assertNotNull($context->startTime());
    }
}

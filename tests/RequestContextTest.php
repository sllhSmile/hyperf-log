<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Context\Context;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Context\TraceContext;

final class RequestContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testStartStoresOneImmutableTraceUnderTheClassName(): void
    {
        $context = new RequestContext();
        $trace = $context->start(' upstream-id ');

        self::assertSame(RequestContext::class, RequestContext::CONTEXT_KEY);
        self::assertInstanceOf(TraceContext::class, $trace);
        self::assertSame('upstream-id', $context->id());
        self::assertSame($trace->startedAt, $context->startTime());
        self::assertSame($trace, $context->current());
    }

    public function testStartGeneratesUuidAndAtomicallyReplacesTrace(): void
    {
        $context = new RequestContext();
        $first = $context->start();
        $second = $context->start('second');

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first->requestId);
        self::assertSame('second', $second->requestId);
        self::assertSame($second, $context->current());
    }

    public function testReadMethodsDoNotImplicitlyCreateAContext(): void
    {
        $context = new RequestContext();

        self::assertNull($context->current());
        self::assertNull($context->id());
        self::assertNull($context->startTime());
        self::assertFalse(Context::has(RequestContext::CONTEXT_KEY));
    }
}

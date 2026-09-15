<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Context\Context;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Support\RequestContext;

class RequestContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testStartCreatesCompleteTraceWithProvidedId(): void
    {
        $context = $this->context();

        $trace = $context->start(' upstream-id ');

        self::assertSame('upstream-id', $trace->requestId);
        self::assertGreaterThan(0, $trace->startedAt);
        self::assertSame($trace, $context->current());
        self::assertSame('upstream-id', $context->id());
        self::assertSame($trace->startedAt, $context->startTime());
    }

    public function testStartGeneratesIdWhenProvidedValueIsEmpty(): void
    {
        $trace = $this->context()->start('  ');

        self::assertNotSame('', $trace->requestId);
        self::assertGreaterThan(0, $trace->startedAt);
    }

    public function testStartingAnotherTraceAtomicallyReplacesPreviousState(): void
    {
        $context = $this->context();
        $first = $context->start('first-trace');

        $second = $context->start('second-trace');

        self::assertNotSame($first, $second);
        self::assertSame('second-trace', $second->requestId);
        self::assertGreaterThanOrEqual($first->startedAt, $second->startedAt);
        self::assertSame($second, $context->current());
    }

    public function testCurrentDoesNotCreateTraceWhenContextIsMissing(): void
    {
        $context = $this->context();

        self::assertNull($context->current());
        self::assertNull($context->id());
        self::assertNull($context->startTime());
        self::assertFalse(Context::has(RequestContext::CONTEXT_KEY));
    }

    public function testCurrentRejectsPartialOrForeignContextValue(): void
    {
        Context::set(RequestContext::CONTEXT_KEY, ['request_id' => 'partial']);

        self::assertNull($this->context()->current());
    }

    private function context(): RequestContext
    {
        return new RequestContext();
    }
}

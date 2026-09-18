<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Command\Command;
use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Context\Context;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Listener\CommandTraceListener;

final class CommandTraceListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItStartsAFreshTraceBeforeEveryCommand(): void
    {
        $context = new RequestContext();
        $context->start('stale-trace');
        $listener = new CommandTraceListener($context);

        $listener->process(new BeforeHandle($this->createMock(Command::class)));

        self::assertNotNull($context->id());
        self::assertNotSame('stale-trace', $context->id());
        $first = $context->current();
        $listener->process(new BeforeHandle($this->createMock(Command::class)));
        self::assertNotSame($first, $context->current());
        self::assertNotSame($first?->requestId, $context->id());
    }

    public function testItIgnoresUnrelatedEvents(): void
    {
        $context = new RequestContext();
        $listener = new CommandTraceListener($context);

        $listener->process(new \stdClass());

        self::assertNull($context->current());
    }
}

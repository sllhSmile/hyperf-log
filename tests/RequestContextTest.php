<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Context\Context;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $trace = $context->start('upstream-id');

        self::assertSame(RequestContext::class, RequestContext::CONTEXT_KEY);
        self::assertInstanceOf(TraceContext::class, $trace);
        self::assertSame('upstream-id', $context->id());
        self::assertSame($trace->startedAt, $context->startTime());
        self::assertSame($trace, $context->current());
    }

    #[DataProvider('invalidRequestIdProvider')]
    public function testStartReplacesUnsafeRequestIdsWithUuidV7(?string $requestId): void
    {
        $trace = (new RequestContext())->start($requestId);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $trace->requestId,
        );
    }

    /** @return array<string, array{?string}> */
    public static function invalidRequestIdProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'space' => ['upstream id'],
            'surrounding whitespace' => [' upstream-id '],
            'control character' => ["upstream\nid"],
            'unicode' => ['链路标识'],
            'over byte limit' => [str_repeat('a', RequestContext::MAX_REQUEST_ID_BYTES + 1)],
        ];
    }

    public function testStartAcceptsRequestIdAtTheByteLimit(): void
    {
        $requestId = str_repeat('a', RequestContext::MAX_REQUEST_ID_BYTES);

        self::assertSame($requestId, (new RequestContext())->start($requestId)->requestId);
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

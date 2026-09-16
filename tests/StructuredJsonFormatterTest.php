<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use DateTimeImmutable;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Formatter\StructuredJsonFormatter;
use Sllhsmile\HyperfLog\Support\LogConfig;

final class StructuredJsonFormatterTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testCollectorUsesVersionedEnvelopeAndReservedFieldsCannotBeOverridden(): void
    {
        $requestContext = new RequestContext();
        $requestContext->start('trace-id');
        $formatter = $this->formatter($requestContext);
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-16T10:20:30.123456+08:00'),
            'apilog',
            Level::Info,
            'http.server',
            [
                'type' => 'evil',
                'request_id' => 'evil',
                'duration_ms' => 12.35,
                'response' => null,
                Collector::LOG_CONTEXT_KEY => Collector::Api,
            ],
        );

        $result = json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['schema_version']);
        self::assertSame('2026-09-16 10:20:30.123456+08:00', $result['timestamp']);
        self::assertSame('INFO', $result['level']);
        self::assertSame('http.server', $result['type']);
        self::assertSame('trace-id', $result['request_id']);
        self::assertSame(12.35, $result['duration_ms']);
        self::assertArrayNotHasKey('response', $result);
        self::assertArrayNotHasKey(Collector::LOG_CONTEXT_KEY, $result);
    }

    public function testTimestampIsAlwaysRenderedInAsiaShanghai(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-16T02:20:30.123456+00:00'),
            'default',
            Level::Info,
            'hello',
        );

        $result = json_decode($this->formatter(new RequestContext())->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-09-16 10:20:30.123456+08:00', $result['timestamp']);
    }

    public function testApplicationLogKeepsMessageAndNestedContext(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'default', Level::Warning, 'hello', [
            'type' => 'business', 'request_id' => 'caller-value',
        ]);
        $result = json_decode($this->formatter(new RequestContext())->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('application', $result['type']);
        self::assertSame('hello', $result['message']);
        self::assertSame('business', $result['context']['type']);
        self::assertSame('caller-value', $result['context']['request_id']);
        self::assertArrayNotHasKey('request_id', $result);
    }

    public function testApplicationMessageMatchingCollectorTypeIsNotMisclassified(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'default', Level::Info, 'http.server', [
            'order_id' => 100,
        ]);

        $result = json_decode($this->formatter(new RequestContext())->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('application', $result['type']);
        self::assertSame('http.server', $result['message']);
        self::assertSame(['order_id' => 100], $result['context']);
    }

    private function formatter(RequestContext $requestContext): StructuredJsonFormatter
    {
        return new StructuredJsonFormatter($requestContext, new LogConfig(new Config(['app_name' => 'xthk'])));
    }
}

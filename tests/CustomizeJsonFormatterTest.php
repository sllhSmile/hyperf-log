<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Formatter\CustomizeJsonFormatter;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\RequestContext;

class CustomizeJsonFormatterTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy('x-b3-traceid');
    }

    public function testItFlattensCollectorContextWithoutOverridingMetadata(): void
    {
        $formatter = $this->formatter('demo');
        $record = new LogRecord(new \DateTimeImmutable('2026-08-25 12:00:00'), 'sdklog', Level::Info, 'sdklog', [
            'request' => ['url' => 'https://example.com'],
            'message_type' => 'must-not-override',
            'request_id' => 'must-not-override',
        ]);

        $output = json_decode($formatter->format($record), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('sdklog', $output['message_type']);
        self::assertNotSame('must-not-override', $output['request_id']);
        self::assertSame(['url' => 'https://example.com'], $output['request']);
    }

    public function testItPreservesPlainMessageAndUsesApplicationLogType(): void
    {
        $formatter = $this->formatter('demo');
        $record = new LogRecord(new \DateTimeImmutable('2026-08-25 12:00:00'), 'default', Level::Info, 'payment complete');

        $output = json_decode($formatter->format($record), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('demo_log', $output['message_type']);
        self::assertSame('payment complete', $output['message']);
        self::assertNotSame('', $output['request_id']);
        self::assertArrayHasKey('coroutine_id', $output);
    }

    public function testItWritesStructuredJsonBodyWithoutDoubleEncoding(): void
    {
        $formatter = $this->formatter('demo');
        $record = new LogRecord(new \DateTimeImmutable('2026-08-25 12:00:00'), 'apilog', Level::Info, 'apilog', [
            'request' => ['body' => ['token' => '****']],
        ]);

        $formatted = $formatter->format($record);
        $output = json_decode($formatted, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['token' => '****'], $output['request']['body']);
        self::assertStringContainsString('"body":{"token":"****"}', $formatted);
        self::assertStringNotContainsString('\\"token\\"', $formatted);
    }

    private function formatter(string $appName): CustomizeJsonFormatter
    {
        $config = new LogConfig(new Config(['app_name' => $appName]));

        return new CustomizeJsonFormatter(new RequestContext($config), $config);
    }
}

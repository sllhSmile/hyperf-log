<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sllhsmile\HyperfLog\Support\InternalDiagnostic;

final class InternalDiagnosticTest extends TestCase
{
    public function testItIncludesUsefulFieldsAndEscapesLineBreaks(): void
    {
        $exception = new RuntimeException("first line\r\nsecond line");

        $message = InternalDiagnostic::formatException('hyperf-log api prepare failed', $exception, 'trace-id');

        self::assertStringContainsString('exception=' . RuntimeException::class, $message);
        self::assertStringContainsString('file=InternalDiagnosticTest.php', $message);
        self::assertStringContainsString('request_id=trace-id', $message);
        self::assertStringContainsString('message=first line\\r\\nsecond line', $message);
        self::assertStringNotContainsString("\r", $message);
        self::assertStringNotContainsString("\n", $message);
    }

    public function testItTruncatesDiagnosticsToTheByteLimit(): void
    {
        $message = InternalDiagnostic::formatException(
            'hyperf-log sdk write failed',
            new RuntimeException(str_repeat('x', InternalDiagnostic::MAX_BYTES * 2)),
            null,
        );

        self::assertSame(InternalDiagnostic::MAX_BYTES, strlen($message));
        self::assertStringEndsWith('...', $message);
    }
}

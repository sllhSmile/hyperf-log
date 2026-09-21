<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Support\HttpLogLevel;

final class HttpLogLevelTest extends TestCase
{
    /** @return array<string, array{mixed, bool, Level}> */
    public static function outcomes(): array
    {
        return [
            'exception wins over success' => [200, true, Level::Error],
            'exception wins over client error' => [404, true, Level::Error],
            'exception without response' => [null, true, Level::Error],
            'informational' => [100, false, Level::Info],
            'success' => [200, false, Level::Info],
            'redirection' => [302, false, Level::Info],
            'client error' => [404, false, Level::Warning],
            'server error' => [503, false, Level::Error],
            'missing status' => [null, false, Level::Info],
            'string status' => ['500', false, Level::Info],
            'below range' => [99, false, Level::Info],
            'above range' => [600, false, Level::Info],
        ];
    }

    #[DataProvider('outcomes')]
    public function testItResolvesHttpOutcomes(mixed $statusCode, bool $hasError, Level $expected): void
    {
        self::assertSame($expected, HttpLogLevel::resolve($statusCode, $hasError));
    }
}

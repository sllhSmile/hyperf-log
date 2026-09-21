<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogLevelDispatcher;
use Sllhsmile\HyperfLog\Support\LogOrigin;

final class LogLevelDispatcherTest extends TestCase
{
    /** @return iterable<string, array{Level, string, bool}> */
    public static function levels(): iterable
    {
        foreach (Level::cases() as $level) {
            foreach ([false, true] as $explicitOrigin) {
                yield strtolower($level->name) . ($explicitOrigin ? ' explicit origin' : ' implicit origin') => [
                    $level,
                    strtolower($level->name),
                    $explicitOrigin,
                ];
            }
        }
    }

    #[DataProvider('levels')]
    public function testItDispatchesEveryLevelWithoutChangingThePublicInterface(
        Level $level,
        string $method,
        bool $explicitOrigin,
    ): void {
        $origin = $explicitOrigin ? new LogOrigin('trace-id', 42) : null;
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method($method)->willReturnCallback(
            static function (...$arguments) use ($origin): void {
                self::assertSame(Collector::Sdk, $arguments[0]);
                self::assertSame(['sequence' => 1], $arguments[1]);
                self::assertSame($origin, $arguments[2]);
            },
        );

        LogLevelDispatcher::write($logger, $level, Collector::Sdk, ['sequence' => 1], $origin);
    }
}

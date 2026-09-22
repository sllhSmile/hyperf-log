<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Logger\LoggerFactory;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\AsyncDispatcher;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogEntry;
use Sllhsmile\HyperfLog\Support\LogMetadata;
use Sllhsmile\HyperfLog\Support\LogOrigin;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class AsyncDispatcherTest extends TestCase
{
    public function testBudgetSaturationRetriesAndSerializesFallbackWithConsumer(): void
    {
        $entered = new Channel(1);
        $release = new Channel(1);
        $finished = new Channel(1);
        $seen = [];
        $active = 0;
        $peakActive = 0;
        $dispatcher = $this->dispatcher('async', static function (string $message, array $context) use (
            $entered,
            $release,
            &$seen,
            &$active,
            &$peakActive,
        ): void {
            ++$active;
            $peakActive = max($peakActive, $active);
            $sequence = $context['sequence'];
            if ($sequence === 1) {
                $entered->push(true);
                self::assertTrue($release->pop(1));
            }
            $seen[] = $sequence;
            --$active;
        }, 1024);

        Coroutine\run(static function () use ($dispatcher, $entered, $release, $finished): void {
            $dispatcher->submit(self::entry(1));
            self::assertTrue($entered->pop(1));
            $dispatcher->submit(self::entry(2));
            self::assertSame(1024, $dispatcher->stats()['queued_bytes']);
            Coroutine::create(static function () use ($dispatcher, $finished): void {
                $dispatcher->submit(self::entry(3));
                $finished->push(true);
            });
            Coroutine::sleep(0.01);
            self::assertSame(1, $dispatcher->stats()['fallbacks']);
            self::assertSame(3, $dispatcher->stats()['pending']);
            $release->push(true);
            self::assertTrue($finished->pop(1));
            $dispatcher->drain();
            $dispatcher->drain();
        });

        self::assertSame(1, $peakActive);
        self::assertEqualsCanonicalizing([1, 2, 3], $seen);
        self::assertSame([
            'queued' => 0, 'queued_bytes' => 0, 'pending' => 0, 'inflight' => 0,
            'fallbacks' => 1, 'writes' => 3, 'failures' => 0, 'dropped' => 0,
        ], $dispatcher->stats());
    }

    public function testDrainTimeoutDropsQueuedEntriesAndReportsStatistics(): void
    {
        $entered = new Channel(1);
        $release = new Channel(1);
        $finished = new Channel(1);
        $seen = [];
        $dispatcher = $this->dispatcher('async', static function (string $message, array $context) use (
            $entered,
            $release,
            &$seen,
        ): void {
            $sequence = $context['sequence'];
            $seen[] = $sequence;
            if ($sequence === 1) {
                $entered->push(true);
                $release->pop();
            }
        }, 1024);

        Coroutine\run(static function () use ($dispatcher, $entered, $release, $finished): void {
            $dispatcher->submit(self::entry(1));
            self::assertTrue($entered->pop(1));
            $dispatcher->submit(self::entry(2));
            $dispatcher->drain();
            self::assertSame(1, $dispatcher->stats()['dropped']);
            self::assertSame(0, $dispatcher->stats()['queued']);
            self::assertSame(1, $dispatcher->stats()['inflight']);
            $dispatcher->drain();
            $release->push(true);
            Coroutine::create(static function () use ($dispatcher, $finished): void {
                while ($dispatcher->stats()['pending'] !== 0) {
                    Coroutine::sleep(0.001);
                }
                $finished->push(true);
            });
            self::assertTrue($finished->pop(1));
        });

        self::assertSame([1], $seen);
        self::assertSame(1, $dispatcher->stats()['dropped']);
        self::assertSame(0, $dispatcher->stats()['pending']);
    }

    public function testRecursiveHandlerLogIsSuppressedWithoutDeadlock(): void
    {
        $calls = 0;
        $dispatcher = null;
        $dispatcher = $this->dispatcher('sync', static function () use (&$calls, &$dispatcher): void {
            ++$calls;
            if (! $dispatcher instanceof AsyncDispatcher) {
                throw new \LogicException('Dispatcher has not been initialized.');
            }
            $dispatcher->submit(self::entry(2));
        });

        Coroutine\run(static function () use ($dispatcher): void {
            $dispatcher->submit(self::entry(1));
        });

        self::assertSame(1, $calls);
        self::assertSame(1, $dispatcher->stats()['failures']);
        self::assertSame(1, $dispatcher->stats()['writes']);
    }

    public function testDrainBeforeFirstAsyncSubmissionIsIdempotent(): void
    {
        $dispatcher = $this->dispatcher('async', static function (): void {});

        $dispatcher->drain();
        $dispatcher->drain();

        self::assertSame(0, $dispatcher->stats()['writes']);
        self::assertSame(0, $dispatcher->stats()['dropped']);
    }

    /** @param callable(string, array<string, mixed>): void $write */
    private function dispatcher(string $mode, callable $write, int $budget = 1024): AsyncDispatcher
    {
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->method('info')->willReturnCallback($write);
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);

        return new AsyncDispatcher($factory, new LogConfig(new Config(['trace_log' => [
            'write_mode' => $mode,
            'async' => ['max_buffer_bytes' => $budget],
        ]])), new RequestContext());
    }

    private static function entry(int $sequence): LogEntry
    {
        return new LogEntry(
            new LogMetadata(Collector::Api, new LogOrigin('trace-id', Coroutine::getCid())),
            Level::Info,
            ['sequence' => $sequence],
            new JsonSerializableDateTimeImmutable(true),
            1024,
        );
    }
}

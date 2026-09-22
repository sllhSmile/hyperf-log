<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Redis\Event\CommandExecuted;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Listener\RedisLogListener;
use Sllhsmile\HyperfLog\Support\LogConfig;

final class RedisLogListenerTest extends TestCase
{
    public function testItAlwaysMasksAuthAndOmitsDisabledResponse(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Info,
            Collector::Redis,
            self::callback(static fn(array $value): bool =>
                $value['request']['command'] === 'AUTH ***' && ! isset($value['response'])),
        );
        $this->listener($logger)->process($this->event('AUTH', ['user', 'secret'], true));
    }

    public function testFailureUsesErrorAndNeverResponse(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Error,
            Collector::Redis,
            self::callback(static fn(array $value): bool =>
                $value['error']['type'] === RuntimeException::class && ! isset($value['response'])),
        );
        $this->listener($logger, true)->process($this->event('GET', ['key'], null, new RuntimeException('failed')));
    }

    public function testSuccessfulResultIsIncludedOnlyWhenEnabled(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            Level::Info,
            Collector::Redis,
            self::callback(static fn(array $value): bool =>
                $value['request']['command'] === 'GET key'
                && $value['response']['body'] === 'value'
                && ! isset($value['error'])),
        );

        $this->listener($logger, true)->process($this->event('GET', ['key'], 'value'));
    }

    public function testDisabledCollectorAndUnrelatedEventsDoNotLog(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $listener = new RedisLogListener(new LogConfig(new Config([])), $logger);

        $listener->process(new \stdClass());
        $listener->process($this->event('GET', ['key'], 'value'));
    }

    public function testCommandFormattingFailureDoesNotEscapeIntoTheExecutedCommand(): void
    {
        $event = new class extends CommandExecuted {
            public function __construct() {}

            public function getFormatCommand(): string
            {
                throw new RuntimeException('format failed');
            }
        };
        $event->command = 'GET';
        $event->parameters = ['key'];
        $event->time = 1.2;
        $event->connectionName = 'default';
        $event->result = 'value';
        $event->throwable = null;
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('log');

        $this->listener($logger)->process($event);

        self::addToAssertionCount(1);
    }

    private function listener(CollectorLoggerInterface $logger, bool $response = false): RedisLogListener
    {
        return new RedisLogListener(new LogConfig(new Config(['trace_log' => ['collectors' => [
            'redis' => ['enabled' => true, 'response_enabled' => $response],
        ]]])), $logger);
    }

    /** @param array<array-key, mixed> $parameters */
    private function event(string $command, array $parameters, mixed $result, ?RuntimeException $error = null): CommandExecuted
    {
        $event = (new ReflectionClass(CommandExecuted::class))->newInstanceWithoutConstructor();
        $event->command = $command;
        $event->parameters = $parameters;
        $event->time = 1.2;
        $event->connectionName = 'default';
        $event->result = $result;
        $event->throwable = $error;

        return $event;
    }
}

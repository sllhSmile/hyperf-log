<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Redis\Event\CommandExecuted;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Sllhsmile\HyperfLog\Listener\RedisLogListener;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

class RedisLogListenerTest extends TestCase
{
    public function testItCombinesCommandAndParametersWithoutRecordingResponseByDefault(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'redislog',
            self::callback(static fn (array $context): bool =>
                $context['request'] === [
                    'connection' => 'default',
                    'command' => 'HSET user:1 name smile password plain-secret',
                ]
                && $context['response'] === null),
        );

        $this->listener($writer)->process($this->event(
            'HSET',
            ['user:1', ['name' => 'smile', 'password' => 'plain-secret']],
            1,
        ));
    }

    public function testItRecordsResultWhenResponseIsEnabled(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'redislog',
            self::callback(static fn (array $context): bool =>
                $context['response'] === ['body' => 'value']
                && $context['duration_ms'] === 1.2),
        );

        $this->listener($writer, true)->process($this->event('GET', ['key'], 'value'));
    }

    public function testItAlwaysMasksAuthCommand(): void
    {
        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'redislog',
            self::callback(static fn (array $context): bool => $context['request']['command'] === 'AUTH ***'),
        );

        $this->listener($writer)->process($this->event('AUTH', ['default', 'redis-password'], true));
    }

    private function listener(LogWriter $writer, bool $responseEnabled = false): RedisLogListener
    {
        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['redislog' => [
                'enabled' => true,
                'response_enabled' => $responseEnabled,
            ]]],
        ]));

        return new RedisLogListener($config, $writer, new RequestContext($config));
    }

    /**
     * RedisConnection 只用于事件类型声明，监听器不会读取它，因此测试不初始化该属性。
     */
    private function event(
        string $command,
        array $parameters,
        mixed $result,
        ?RuntimeException $throwable = null,
    ): CommandExecuted {
        $event = (new ReflectionClass(CommandExecuted::class))->newInstanceWithoutConstructor();
        $event->command = $command;
        $event->parameters = $parameters;
        $event->time = 1.2;
        $event->connectionName = 'default';
        $event->result = $result;
        $event->throwable = $throwable;

        return $event;
    }
}

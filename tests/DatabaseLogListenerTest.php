<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Listener\DatabaseLogListener;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

/**
 * 验证数据库日志的完整 SQL 参数展开逻辑。
 */
class DatabaseLogListenerTest extends TestCase
{
    public function testItUsesTheUnifiedResponseAndDurationShape(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prepareBindings')->willReturnCallback(static fn (array $bindings): array => $bindings);
        $connection->method('getDatabaseName')->willReturn('app');
        $connection->method('getName')->willReturn('default');

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'dblog',
            self::callback(static fn (array $context): bool =>
                $context['response'] === ['body' => [['id' => 1]]]
                && $context['duration_ms'] === 2.5
                && ! array_key_exists('run_time', $context)),
        );
        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['dblog' => ['enabled' => true, 'response_enabled' => true]]],
        ]));
        $listener = new DatabaseLogListener($config, $writer, new RequestContext());
        $event = new \Hyperf\Database\Events\QueryExecuted('select 1', [], 2.5, $connection, [['id' => 1]]);

        $listener->process($event);
    }

    /**
     * 覆盖顺序参数、命名参数、NULL、布尔、数字、引号以及 SQL 字符串内问号。
     */
    public function testItInterpolatesBindingsWithoutChangingSqlLiterals(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('prepareBindings')->willReturnCallback(static fn (array $bindings): array => $bindings);

        $config = new LogConfig(new Config([]));
        $listener = new DatabaseLogListener(
            $config,
            $this->createMock(LogWriter::class),
            new RequestContext(),
        );
        $method = new \ReflectionMethod($listener, 'interpolateSql');

        $sql = $method->invoke(
            $listener,
            "select '?' as literal, * from users where id = ? and active = ? and deleted_at is ? and name = :name",
            [7, true, null, 'name' => "O'Reilly"],
            $connection,
        );

        self::assertSame("select '?' as literal, * from users where id = 7 and active = 1 and deleted_at is NULL and name = 'O''Reilly'", $sql);
    }
}

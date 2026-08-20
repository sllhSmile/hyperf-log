<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
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
    /**
     * 覆盖顺序参数、命名参数、NULL、布尔、数字、引号以及 SQL 字符串内问号。
     */
    public function testItInterpolatesBindingsWithoutChangingSqlLiterals(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('prepareBindings')->willReturnCallback(static fn (array $bindings): array => $bindings);

        $listener = new DatabaseLogListener(
            new LogConfig(new Config([])),
            $this->createMock(LogWriter::class),
            new RequestContext(new LogConfig(new Config([]))),
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

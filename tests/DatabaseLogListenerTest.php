<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Database\Connection;
use Hyperf\Database\Events\QueryExecuted;
use PDO;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Listener\DatabaseLogListener;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\SqlInterpolator;

final class DatabaseLogListenerTest extends TestCase
{
    public function testItInterpolatesBindingsWithoutTouchingQuotedOrCommentedMarkers(): void
    {
        $connection = $this->connection();
        $sql = "select '?' as literal, name from users where id = ? and name = :name -- ?\n";

        $result = (new SqlInterpolator())->interpolate($sql, [7, 'name' => "O'Reilly"], $connection);

        self::assertSame("select '?' as literal, name from users where id = 7 and name = 'O''Reilly' -- ?\n", $result);
    }

    public function testListenerOmitsResponseUnlessExplicitlyEnabled(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Database,
            self::callback(static fn(array $value): bool =>
                $value['duration_ms'] === 2.5 && ! isset($value['response'])),
        );
        $config = new LogConfig(new Config(['trace_log' => ['collectors' => [
            'database' => ['enabled' => true, 'response_enabled' => false],
        ]]]));
        $listener = new DatabaseLogListener($config, $logger, new SqlInterpolator());
        $listener->process(new QueryExecuted('select ?', [1], 2.5, $this->connection(), ['row']));
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prepareBindings')->willReturnArgument(0);
        $connection->method('getDatabaseName')->willReturn('testing');
        $connection->method('getName')->willReturn('default');
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'");
        $connection->method('getPdo')->willReturn($pdo);

        return $connection;
    }

    public function testListenerIncludesExplicitlyEnabledResult(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            Collector::Database,
            self::callback(static fn(array $value): bool =>
                $value['request']['sql'] === 'select 1'
                && $value['response']['body'] === ['row']),
        );
        $config = new LogConfig(new Config(['trace_log' => ['collectors' => [
            'database' => ['enabled' => true, 'response_enabled' => true],
        ]]]));

        (new DatabaseLogListener($config, $logger, new SqlInterpolator()))
            ->process(new QueryExecuted('select ?', [1], 2.5, $this->connection(), ['row']));
    }

    public function testDisabledCollectorAndUnrelatedEventsDoNotLog(): void
    {
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $listener = new DatabaseLogListener(new LogConfig(new Config([])), $logger, new SqlInterpolator());

        $listener->process(new \stdClass());
        $listener->process(new QueryExecuted('select ?', [1], 2.5, $this->connection()));
    }

}

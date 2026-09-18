<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Support\SqlInterpolator;

final class SqlInterpolatorTest extends TestCase
{
    public function testItPreservesMarkersInQuotedIdentifiersAndComments(): void
    {
        $identifier = chr(96) . '?' . chr(96);
        $sql = "select '?', \"?\", {$identifier}, 'it''s ?', 'escaped\\'?', ? /* :name ? */ # ?\n-- ?\n";
        $expected = "select '?', \"?\", {$identifier}, 'it''s ?', 'escaped\\'?', 7 /* :name ? */ # ?\n-- ?\n";

        self::assertSame($expected, (new SqlInterpolator())->interpolate($sql, [7], $this->connection()));
    }

    public function testItSupportsRepeatedNamedBindingsAndColonPrefixedKeys(): void
    {
        $result = (new SqlInterpolator())->interpolate(
            'select :name, :name, :other, :missing',
            ['name' => "O'Reilly", ':other' => 'value'],
            $this->connection(),
        );

        self::assertSame("select 'O''Reilly', 'O''Reilly', 'value', :missing", $result);
    }

    public function testItFormatsScalarsAndPreservesUnboundPlaceholders(): void
    {
        $result = (new SqlInterpolator())->interpolate(
            'select ?, ?, ?, ?, ?, ?',
            [null, true, false, 42, 1.5],
            $this->connection(),
        );

        self::assertSame('select NULL, 1, 0, 42, 1.5, ?', $result);
    }

    public function testItUsesPreparedBindingsAndPdoQuoting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('prepareBindings')->with(['raw'])->willReturn(['prepared']);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('quote')->with('prepared')->willReturn("'driver-quoted'");
        $connection->method('getPdo')->willReturn($pdo);

        self::assertSame("'driver-quoted'", (new SqlInterpolator())->interpolate('?', ['raw'], $connection));
    }

    public function testItFallsBackToEscapingWhenPdoCannotQuote(): void
    {
        self::assertSame(
            "'O''Reilly'",
            (new SqlInterpolator())->interpolate('?', ["O'Reilly"], $this->connection(false)),
        );
    }

    private function connection(bool $quote = true): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prepareBindings')->willReturnArgument(0);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(
            static fn(string $value): string|false => $quote ? "'" . str_replace("'", "''", $value) . "'" : false,
        );
        $connection->method('getPdo')->willReturn($pdo);

        return $connection;
    }
}

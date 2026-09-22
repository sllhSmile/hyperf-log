<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Database\ConnectionInterface;

/**
 * 将 QueryExecuted 的 bindings 插入 SQL，生成仅供日志排障的可读文本。
 *
 * 扫描器跳过字符串、标识符和 SQL 注释中的占位符，支持位置参数及命名参数。输出结果
 * 绝不能重新交给数据库执行；字符串仍优先复用当前连接 PDO 的 quote 规则。
 */
final class SqlInterpolator
{
    /** 跳过 SQL 字面量和注释，只替换实际位置或命名占位符。
     * @param array<int|string, mixed> $bindings
     */
    public function interpolate(string $sql, array $bindings, ConnectionInterface $connection): string
    {
        $bindings = $connection->prepareBindings($bindings);
        $position = 0;
        $length = strlen($sql);
        $result = '';
        $quote = null;

        for ($index = 0; $index < $length; ++$index) {
            $character = $sql[$index];
            if ($quote !== null) {
                $result .= $character;
                if ($character === $quote) {
                    if (($sql[$index + 1] ?? '') === $quote) {
                        $result .= $sql[++$index];
                    } else {
                        $quote = null;
                    }
                } elseif ($character === '\\' && $quote !== '`' && isset($sql[$index + 1])) {
                    $result .= $sql[++$index];
                }
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $result .= $character;
                continue;
            }
            if (($character === '-' && ($sql[$index + 1] ?? '') === '-') || $character === '#') {
                $end = strpos($sql, "\n", $index);
                $end = $end === false ? $length : $end;
                $result .= substr($sql, $index, $end - $index);
                $index = $end - 1;
                continue;
            }
            if ($character === '/' && ($sql[$index + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $end = $end === false ? $length : $end + 2;
                $result .= substr($sql, $index, $end - $index);
                $index = $end - 1;
                continue;
            }
            if ($character === '?' && array_key_exists($position, $bindings)) {
                $result .= $this->quote($bindings[$position++], $connection);
                continue;
            }
            if ($character === ':' && ($sql[$index + 1] ?? '') !== ':'
                && preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $match, 0, $index)) {
                $name = $match[1];
                $key = array_key_exists($name, $bindings) ? $name : ':' . $name;
                if (array_key_exists($key, $bindings)) {
                    $result .= $this->quote($bindings[$key], $connection);
                    $index += strlen($match[0]) - 1;
                    continue;
                }
            }
            $result .= $character;
        }

        return $result;
    }

    /** 优先使用连接 PDO 规则生成仅供展示的绑定值文本。 */
    private function quote(mixed $value, ConnectionInterface $connection): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $string = (string) $value;
        if (method_exists($connection, 'getPdo')) {
            $quoted = $connection->getPdo()->quote($string);
            if ($quoted !== false) {
                return $quoted;
            }
        }

        return "'" . str_replace("'", "''", $string) . "'";
    }
}

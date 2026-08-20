<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

class DatabaseLogListener implements ListenerInterface
{
    /**
     * 注入数据库日志所需配置、写入器和请求上下文。
     */
    public function __construct(
        private LogConfig $config,
        private LogWriter $writer,
        private RequestContext $requestContext,
    ) {
    }

    public function listen(): array
    {
        // QueryExecuted 在每条 SQL 执行结束后触发。
        return [QueryExecuted::class];
    }

    /**
     * 将数据库查询事件转换为结构化 dblog 日志。
     *
     * @param object $event Hyperf 事件调度器传入的事件对象
     */
    public function process(object $event): void
    {
        // 只处理数据库事件，且 dblog 未开启时不读取事件数据、不创建日志记录器。
        if (! $event instanceof QueryExecuted || ! $this->config->enabled('dblog')) {
            return;
        }

        // 第 1 步：将已执行 SQL 的绑定值展开，便于直接复制日志中的语句进行排查。
        $sql = $this->interpolateSql($event->sql, $event->bindings, $event->connection);

        // 第 2 步：查询结果可能很大，只有明确开启 dblog.response_enabled 才保留。
        $response = $this->config->responseEnabled('dblog') ? $event->result : null;

        // 第 3 步：日志上下文沿用项目既有 dblog 的 app_name/request/response/耗时结构。
        $this->writer->info('dblog', [
            'request_id' => $this->requestContext->id(),
            'app_name' => $this->config->appName(),
            'request' => [
                'database' => $event->connection->getDatabaseName(),
                'sql' => $sql,
                'connection' => $event->connectionName,
            ],
            'response' => $response,
            'start_time' => null,
            'end_time' => null,
            // QueryExecuted::$time 单位为毫秒，与原 dblog 的 run_time 字段保持一致。
            'run_time' => $event->time,
        ]);
    }

    /**
     * 将数据库事件中的绑定参数替换为 SQL 字面量。
     *
     * 先委托 Hyperf Connection 规范日期、布尔等绑定值，再扫描 SQL：只替换 SQL
     * 语法层面的顺序 `?` 或命名 `:name` 占位符，不处理单引号、双引号、反引号及
     * 单行/块注释中的同名字符，避免将 SQL 正文本身误当作绑定参数。
     *
     * @param string $sql 带占位符的原始 SQL
     * @param array<int|string, mixed> $bindings 数据库事件携带的绑定值
     * @param ConnectionInterface $connection 执行该 SQL 的数据库连接
     */
    private function interpolateSql(string $sql, array $bindings, ConnectionInterface $connection): string
    {
        $bindings = $connection->prepareBindings($bindings);
        $position = 0;
        $length = strlen($sql);
        $result = '';
        $quote = null;

        for ($index = 0; $index < $length; ++$index) {
            $character = $sql[$index];

            // 保留字符串和标识符引用内容，引用内的 ? 或 :name 不是绑定参数。
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

            // 单行注释直到换行结束，不替换其中的占位符文本。
            if (($character === '-' && ($sql[$index + 1] ?? '') === '-') || $character === '#') {
                $end = strpos($sql, "\n", $index);
                $end = $end === false ? $length : $end;
                $result .= substr($sql, $index, $end - $index);
                $index = $end - 1;
                continue;
            }

            // 块注释直到 */ 结束，不替换其中的占位符文本。
            if ($character === '/' && ($sql[$index + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $end = $end === false ? $length : $end + 2;
                $result .= substr($sql, $index, $end - $index);
                $index = $end - 1;
                continue;
            }

            if ($character === '?' && array_key_exists($position, $bindings)) {
                $result .= $this->quoteBinding($bindings[$position], $connection);
                ++$position;
                continue;
            }

            // PostgreSQL 等数据库的 :: 类型转换不是命名绑定；其余 :name 作为命名绑定处理。
            if ($character === ':' && ($sql[$index + 1] ?? '') !== ':' && preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $match, 0, $index)) {
                $name = $match[1];
                $key = array_key_exists($name, $bindings) ? $name : ':' . $name;
                if (array_key_exists($key, $bindings)) {
                    $result .= $this->quoteBinding($bindings[$key], $connection);
                    $index += strlen($match[0]) - 1;
                    continue;
                }
            }

            $result .= $character;
        }

        return $result;
    }

    /**
     * 将单个绑定值转换为可写入完整 SQL 的字面量。
     *
     * 字符串优先使用实际 PDO 驱动的 quote()，以匹配连接字符集和数据库转义规则；
     * 无法取得 PDO 的测试或特殊连接场景使用标准单引号转义作为安全回退。
     */
    private function quoteBinding(mixed $value, ConnectionInterface $connection): string
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

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Contract\ListenerInterface;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\SqlInterpolator;
use Throwable;

/**
 * 将 QueryExecuted 转换为 database.query 日志。
 *
 * SQL 插值仅用于排障展示，不能回送数据库执行；查询结果默认不记录，必须由
 * response_enabled 显式开启，并仍受统一容量限制。
 */
final readonly class DatabaseLogListener implements ListenerInterface
{
    public function __construct(
        private LogConfig $config,
        private CollectorLoggerInterface $logger,
        private SqlInterpolator $sqlInterpolator,
    ) {}

    public function listen(): array
    {
        return [QueryExecuted::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof QueryExecuted || ! $this->config->enabled(Collector::Database)) {
            return;
        }
        $context = [
            'duration_ms' => $event->time,
            'request' => [
                'database' => $event->connection->getDatabaseName(),
                'connection' => $event->connectionName,
                'sql' => $this->sqlInterpolator->interpolate($event->sql, $event->bindings, $event->connection),
            ],
        ];
        if ($this->config->responseEnabled(Collector::Database)) {
            $context['response'] = ['body' => $event->result];
        }

        if ($event->result instanceof Throwable) {
            $this->logger->error(Collector::Database, $context);
        } else {
            $this->logger->info(Collector::Database, $context);
        }
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Contract\ListenerInterface;
use Monolog\Level;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\InternalDiagnostic;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\SqlInterpolator;
use Throwable;

/**
 * 将 QueryExecuted 转换为 database.query 日志。
 *
 * SQL 插值仅用于排障展示，不能回送数据库执行；插值结果可能包含绑定参数，数据库日志
 * 不应用 HTTP 字段脱敏规则。查询结果默认不记录，必须由 response_enabled 显式开启，
 * 并仍受统一容量限制。
 *
 * 本监听器只消费 Hyperf 派发的 QueryExecuted。数据库执行在事件派发前直接抛出的异常
 * 不会到达这里，因此不能依靠本监听器覆盖所有失败查询。
 */
final readonly class DatabaseLogListener implements ListenerInterface
{
    /** SQL 插值仅用于日志展示，注入独立处理器避免改变数据库调用。 */
    public function __construct(
        private LogConfig $config,
        private CollectorLoggerInterface $logger,
        private SqlInterpolator $sqlInterpolator,
    ) {}

    /** 只监听数据库完成查询后派发的 QueryExecuted。
     * @return class-string[]
     */
    public function listen(): array
    {
        return [QueryExecuted::class];
    }

    /** 将已完成的查询转换为日志；插值或日志实现失败不改变查询结果。 */
    public function process(object $event): void
    {
        if (! $event instanceof QueryExecuted || ! $this->config->enabled(Collector::Database)) {
            return;
        }
        try {
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

            $this->logger->log(
                $event->result instanceof Throwable ? Level::Error : Level::Info,
                Collector::Database,
                $context,
            );
        } catch (Throwable $exception) {
            InternalDiagnostic::reportException('hyperf-log database prepare failed', $exception, (new RequestContext())->id());
        }
    }
}

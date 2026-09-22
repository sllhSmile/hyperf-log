<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Redis\Event\CommandExecuted;
use Monolog\Level;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\InternalDiagnostic;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Throwable;

/**
 * 将 Redis CommandExecuted 转换为 redis.command 日志。
 *
 * AUTH 参数无条件遮蔽，不受通用 sensitive_fields 配置影响；命令异常优先于可选结果输出。
 */
final readonly class RedisLogListener implements ListenerInterface
{
    /** 共享类型化配置和采集日志接口，不保存事件数据。 */
    public function __construct(private LogConfig $config, private CollectorLoggerInterface $logger) {}

    /** 只监听 Hyperf Redis 已派发的命令执行事件。
     * @return class-string[]
     */
    public function listen(): array
    {
        return [CommandExecuted::class];
    }

    /** 记录已完成的 Redis 命令；格式化或日志实现失败不影响原命令。 */
    public function process(object $event): void
    {
        if (! $event instanceof CommandExecuted || ! $this->config->enabled(Collector::Redis)) {
            return;
        }
        try {
            $context = [
                'duration_ms' => $event->time,
                'request' => [
                    'connection' => $event->connectionName,
                    // AUTH 的凭据必须在格式化参数之前遮蔽，与可配置字段列表无关。
                    'command' => strtoupper($event->command) === 'AUTH' ? 'AUTH ***' : $event->getFormatCommand(),
                ],
            ];
            if ($event->throwable !== null) {
                $context['error'] = [
                    'type' => $event->throwable::class,
                    'message' => $event->throwable->getMessage(),
                    'code' => $event->throwable->getCode(),
                ];
            } elseif ($this->config->responseEnabled(Collector::Redis)) {
                $context['response'] = ['body' => $event->result];
            }

            $this->logger->log(
                $event->throwable !== null ? Level::Error : Level::Info,
                Collector::Redis,
                $context,
            );
        } catch (Throwable $exception) {
            InternalDiagnostic::reportException('hyperf-log redis prepare failed', $exception, (new RequestContext())->id());
        }
    }
}

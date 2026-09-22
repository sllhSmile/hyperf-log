<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Redis\Event\CommandExecuted;
use Monolog\Level;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogConfig;

/**
 * 将 Redis CommandExecuted 转换为 redis.command 日志。
 *
 * AUTH 参数无条件遮蔽，不受通用 sensitive_fields 配置影响；命令异常优先于可选结果输出。
 */
final readonly class RedisLogListener implements ListenerInterface
{
    public function __construct(private LogConfig $config, private CollectorLoggerInterface $logger) {}

    /** @return class-string[] */
    public function listen(): array
    {
        return [CommandExecuted::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof CommandExecuted || ! $this->config->enabled(Collector::Redis)) {
            return;
        }
        $context = [
            'duration_ms' => $event->time,
            'request' => [
                'connection' => $event->connectionName,
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
    }
}

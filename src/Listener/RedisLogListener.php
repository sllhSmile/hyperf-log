<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Redis\Event\CommandExecuted;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

class RedisLogListener implements ListenerInterface
{
    /**
     * 注入 Redis 日志所需配置、写入器和请求上下文。
     */
    public function __construct(
        private LogConfig $config,
        private LogWriter $writer,
        private RequestContext $requestContext,
    ) {
    }

    public function listen(): array
    {
        // CommandExecuted 在 Redis 命令执行成功或失败后触发。
        return [CommandExecuted::class];
    }

    /**
     * 将 Redis 命令事件转换为结构化 redislog 日志。
     *
     * @param object $event Hyperf 事件调度器传入的事件对象
     */
    public function process(object $event): void
    {
        // 只处理 Redis 命令事件，且 redislog 未开启时立即返回。
        if (! $event instanceof CommandExecuted || ! $this->config->enabled('redislog')) {
            return;
        }

        // 原始参数单独记录，formatted_command 便于人工排查；生产环境应由 processor 脱敏。
        $this->writer->info('redislog', [
            'request_id' => $this->requestContext->id(),
            'app_name' => $this->config->appName(),
            'request' => [
                'connection' => $event->connectionName,
                'command' => $this->formatCommand($event),
                'parameters' => $event->parameters,
            ],
            'exception' => $event->throwable ? [
                'class' => $event->throwable::class,
                'error' => $event->throwable->getMessage(),
                'code' => $event->throwable->getCode(),
            ] : null,
            'response' => $event->throwable ? [
                'error' => $event->throwable->getMessage(),
                'code' => $event->throwable->getCode(),
            ] : $event->result,
            'start_time' => null,
            'end_time' => null,
            // CommandExecuted::$time 单位为毫秒，保持原 redislog 的字符串格式。
            'run_time' => $event->time . ' ms',
        ]);
    }

    /**
     * 格式化 Redis 命令，同时遮蔽 AUTH 密码。
     *
     * @param CommandExecuted $event Redis 执行完成事件
     */
    private function formatCommand(CommandExecuted $event): string
    {
        // AUTH 的参数是敏感凭据，禁止直接写入日志。
        if (strtoupper($event->command) === 'AUTH') {
            return 'AUTH ***';
        }

        return $event->getFormatCommand();
    }
}

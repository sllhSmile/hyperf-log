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
     * 注入 Redis 日志依赖；RequestContext 参数保留既有公开构造签名。
     */
    public function __construct(
        private LogConfig $config,
        private LogWriter $writer,
        protected RequestContext $requestContext,
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

        // 与 dblog 一致，命令和参数合并为一条可直接阅读的完整命令，避免再重复记录
        // parameters。Redis 命令缺少统一的字段语义，除 AUTH 外不承诺字段级脱敏。
        $response = $this->config->responseEnabled('redislog')
            ? ['body' => $event->throwable ? null : $event->result]
            : null;

        $this->writer->info('redislog', [
            'app_name' => $this->config->appName(),
            'request' => [
                'connection' => $event->connectionName,
                'command' => $this->formatCommand($event),
            ],
            'exception' => $event->throwable ? [
                'class' => $event->throwable::class,
                'message' => $event->throwable->getMessage(),
                'code' => $event->throwable->getCode(),
            ] : null,
            'response' => $response,
            'start_time' => null,
            'end_time' => null,
            // CommandExecuted::$time 已经以毫秒为单位，保留数值便于日志平台聚合。
            'duration_ms' => $event->time,
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

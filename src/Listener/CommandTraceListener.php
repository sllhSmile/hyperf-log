<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Event\Contract\ListenerInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\RequestContext;

/**
 * 命令行执行前的全局 trace 上下文初始化监听器。
 *
 * Hyperf CLI 不会经过 HTTP PSR-15 中间件，因此必须监听 BeforeHandle，在每个命令
 * 执行前生成 request-id 和开始时间。这样命令内产生的 Redis、数据库、SDK 日志可以
 * 使用同一条追踪标识。
 */
class CommandTraceListener implements ListenerInterface
{
    /**
     * @param LogConfig $config 用于判断是否存在已启用的日志采集器
     * @param RequestContext $requestContext 用于建立当前命令的追踪上下文
     */
    public function __construct(
        private readonly LogConfig $config,
        private readonly RequestContext $requestContext,
    ) {
    }

    /**
     * 声明监听每个 Hyperf Command 的执行前事件。
     *
     * @return class-string[]
     */
    public function listen(): array
    {
        return [BeforeHandle::class];
    }

    /**
     * 在命令处理开始前创建 trace 上下文。
     *
     * @param object $event Hyperf 命令事件对象
     */
    public function process(object $event): void
    {
        // 第 1 步：确认当前事件确实来自命令执行前阶段，并跳过所有采集器关闭的情况。
        if (! $event instanceof BeforeHandle || ! $this->config->anyEnabled()) {
            return;
        }

        // 第 2 步：CLI 不经过 HTTP Middleware，因此在当前命令上下文生成 request-id 和开始时间。
        $this->requestContext->initializeTrace();
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Event\Contract\ListenerInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;

/**
 * 命令行执行前的全局 trace 上下文初始化监听器。
 *
 * Hyperf CLI 不会经过 HTTP PSR-15 中间件，因此必须监听 BeforeHandle，在每个命令
 * 执行前生成 request-id 和开始时间。这样命令内产生的 Redis、数据库、SDK 日志可以
 * 使用同一条追踪标识。
 */
final class CommandTraceListener implements ListenerInterface
{
    public function __construct(
        private readonly RequestContext $requestContext,
    ) {}

    /** @return class-string[] */
    public function listen(): array
    {
        return [BeforeHandle::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof BeforeHandle) {
            return;
        }

        // 每个命令都覆盖旧上下文，防止常驻 Worker 的后续任务沿用上一条 request-id。
        $this->requestContext->start();
    }
}

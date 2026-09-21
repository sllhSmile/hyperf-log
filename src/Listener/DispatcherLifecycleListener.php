<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Command\Event\AfterExecute;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnWorkerExit;
use Hyperf\Server\Event\AllCoroutineServersClosed;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Support\CollectorLogger;

/**
 * 在 Worker、协程 Server 或 CLI 退出阶段等待内置异步队列收尾。
 *
 * 这里只能识别包内置 CollectorLogger；替换 CollectorLoggerInterface 的宿主实现若持有
 * 自己的异步资源，需要自行注册生命周期监听器。
 */
final class DispatcherLifecycleListener implements ListenerInterface
{
    public function __construct(private readonly CollectorLoggerInterface $logger) {}

    /** @return class-string[] */
    public function listen(): array
    {
        return [OnWorkerExit::class, AllCoroutineServersClosed::class, AfterExecute::class];
    }

    public function process(object $event): void
    {
        if ($event instanceof OnWorkerExit || $event instanceof AllCoroutineServersClosed || $event instanceof AfterExecute) {
            if ($this->logger instanceof CollectorLogger) {
                $this->logger->drain();
            }
        }
    }
}

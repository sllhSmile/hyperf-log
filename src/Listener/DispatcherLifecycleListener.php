<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Command\Event\AfterExecute;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnWorkerExit;
use Hyperf\Server\Event\AllCoroutineServersClosed;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Support\CollectorLogger;

/** Drains async dispatchers before Hyperf resumes Worker/CLI shutdown. */
final class DispatcherLifecycleListener implements ListenerInterface
{
    public function __construct(private readonly CollectorLoggerInterface $logger) {}

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

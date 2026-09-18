<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Logger\LoggerFactory;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Throwable;

/**
 * 统一执行采集日志的内容保护、异步调度和异常隔离。
 *
 * async 模式在 Hyperf 协程中 fork 子协程，并只复制 RequestContext 的 Context key；
 * sync 模式和非协程环境直接写入。异步调度失败时同步回退，所有处理和写入异常均在此
 * 终止，不向业务代码传播。异步方式不提供落盘确认，因此不适合作为审计日志。
 */
final readonly class CollectorLogger implements CollectorLoggerInterface
{
    public function __construct(
        private LoggerFactory $factory,
        private LogConfig $config,
        private RequestContext $requestContext,
        private PayloadProcessorInterface $payloadProcessor,
    ) {}

    /** @param array<string, mixed> $context */
    public function info(Collector $collector, array $context): void
    {
        if ($this->config->writeMode() === 'sync' || ! Coroutine::inCoroutine()) {
            $this->safelyWrite($collector, $context);
            return;
        }

        try {
            // 显式复制 request context，保证子协程 formatter 仍能取得当前 request-id。
            $coroutineId = Coroutine::fork(
                fn() => $this->safelyWrite($collector, $context),
                [RequestContext::CONTEXT_KEY],
            );
            if ($coroutineId >= 0) {
                return;
            }
            $this->reportDispatchFailure($collector);
        } catch (Throwable $exception) {
            $this->reportDispatchFailure($collector, $exception);
        }

        // 协程资源耗尽等调度失败场景优先保住日志，并继续隔离实际 Handler 异常。
        $this->safelyWrite($collector, $context);
    }

    /** @param array<string, mixed> $context */
    private function safelyWrite(Collector $collector, array $context): void
    {
        try {
            $context = $this->payloadProcessor->process($collector, $context);
            // 内容保护完成后再加入内部标记，避免标记参与脱敏和容量计算。
            $context[Collector::LOG_CONTEXT_KEY] = $collector;
            $this->factory->get(
                $collector->defaultChannel(),
                $this->config->loggerChannel(),
            )->info($collector->type(), $context);
        } catch (Throwable $exception) {
            $this->reportFailure($collector, $exception);
        }
    }

    private function reportFailure(Collector $collector, Throwable $exception): void
    {
        error_log(sprintf(
            'hyperf-log %s write failed: %s request_id=%s',
            $collector->value,
            $exception::class,
            $this->requestContext->id() ?? 'unavailable',
        ));
    }

    private function reportDispatchFailure(Collector $collector, ?Throwable $exception = null): void
    {
        error_log(sprintf(
            'hyperf-log %s async dispatch failed: %s; falling back to sync request_id=%s',
            $collector->value,
            $exception === null ? 'unknown error' : $exception::class,
            $this->requestContext->id() ?? 'unavailable',
        ));
    }
}

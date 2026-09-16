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
 * 在 Hyperf 协程中 fork 子协程，避免文件或网络 Handler 阻塞业务调用链，并只复制
 * RequestContext 的 Context key；非协程环境同步写入。所有处理和写入异常都会在此终止，
 * 不向业务代码传播。这里的异步方式不提供落盘确认，因此不适合作为审计日志。
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
        if (Coroutine::inCoroutine()) {
            try {
                // 显式复制 request context，保证子协程 formatter 仍能取得当前 request-id。
                Coroutine::fork(fn() => $this->safelyWrite($collector, $context), [RequestContext::CONTEXT_KEY]);
            } catch (Throwable $exception) {
                $this->reportFailure($collector, $exception);
            }

            return;
        }
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
}

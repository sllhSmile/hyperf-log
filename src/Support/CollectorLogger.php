<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Logger\LoggerFactory;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Throwable;

/**
 * 在调用方执行单元中完成内容保护和提交快照，再交给 dispatcher 写入。
 *
 * async 只异步化 Handler IO，脱敏、限流、时间与链路来源都在提交前确定。准备或写入失败
 * 只报告内部错误，不向业务调用方传播。
 */
final readonly class CollectorLogger implements CollectorLoggerInterface
{
    public function __construct(
        LoggerFactory $factory,
        LogConfig $config,
        private RequestContext $requestContext,
        private PayloadProcessorInterface $payloadProcessor,
    ) {
        $this->dispatcher = new AsyncDispatcher($factory, $config, $requestContext);
    }

    private readonly AsyncDispatcher $dispatcher;

    /** 停止接收异步任务并等待已有任务完成；同步模式下无操作。 */
    public function drain(): void
    {
        $this->dispatcher->drain();
    }

    /** @param array<string, mixed> $context */
    public function log(Level $level, Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $datetime = new JsonSerializableDateTimeImmutable(true);
        $origin ??= new LogOrigin($this->requestContext->id(), Coroutine::id());
        try {
            $context = $this->payloadProcessor->process($collector, $context);
            $encoded = json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $estimatedBytes = max(1024, (is_string($encoded) ? strlen($encoded) : 0) + 512);
            $this->dispatcher->submit(new LogEntry(
                new LogMetadata($collector, $origin),
                $level,
                $context,
                $datetime,
                $estimatedBytes,
            ));
        } catch (Throwable $exception) {
            InternalDiagnostic::reportException(
                sprintf('hyperf-log %s prepare failed', $collector->value),
                $exception,
                $origin->requestId,
            );
        }
    }
}

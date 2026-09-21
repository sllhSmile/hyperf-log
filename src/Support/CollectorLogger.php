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

/** Creates immutable event snapshots and delegates delivery to the dispatcher. */
final readonly class CollectorLogger implements CollectorLoggerInterface
{
    public function __construct(
        LoggerFactory $factory,
        LogConfig $config,
        private RequestContext $requestContext,
        private PayloadProcessorInterface $payloadProcessor,
    ) {
        $mode = $config->writeMode();
        if ($mode === WriteMode::ASYNC) {
            $config->asyncMaxBufferBytes();
        }
        $this->dispatcher = new AsyncDispatcher($factory, $config, $requestContext);
    }

    private readonly AsyncDispatcher $dispatcher;

    public function drain(): void
    {
        $this->dispatcher->drain();
    }

    public function emergency(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Emergency, $collector, $context, $origin);
    }

    public function alert(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Alert, $collector, $context, $origin);
    }

    public function critical(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Critical, $collector, $context, $origin);
    }

    public function error(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Error, $collector, $context, $origin);
    }

    public function warning(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Warning, $collector, $context, $origin);
    }

    public function notice(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Notice, $collector, $context, $origin);
    }

    public function info(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Info, $collector, $context, $origin);
    }

    public function debug(Collector $collector, array $context, ?LogOrigin $origin = null): void
    {
        $this->write(Level::Debug, $collector, $context, $origin);
    }

    /** @param array<string, mixed> $context */
    private function write(Level $level, Collector $collector, array $context, ?LogOrigin $origin): void
    {
        $datetime = new JsonSerializableDateTimeImmutable(true);
        $origin ??= new LogOrigin($this->requestContext->id(), Coroutine::id());
        try {
            $context = $this->payloadProcessor->process($collector, $context);
            $encoded = json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $estimatedBytes = max(1024, (is_string($encoded) ? strlen($encoded) : 0) + 512);
            $this->dispatcher->submit(new LogEntry(
                new LogMetadata($collector, $origin->requestId, $origin->coroutineId),
                $level,
                $context,
                $datetime,
                $estimatedBytes,
            ));
        } catch (Throwable $exception) {
            error_log(sprintf(
                'hyperf-log %s prepare failed: %s request_id=%s',
                $collector->value,
                $exception::class,
                $origin->requestId ?? 'unavailable',
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel;
use Hyperf\Logger\LoggerFactory;
use Monolog\Logger as MonologLogger;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Enum\Collector;
use Throwable;

/**
 * One bounded, per-worker consumer. Swoole channels are count bounded, so byte
 * accounting is maintained separately and the channel capacity is only a
 * derived safety ceiling.
 */
final class AsyncDispatcher
{
    private const MIN_ENTRY_BYTES = 1024;
    private const ENQUEUE_WAIT_SECONDS = 0.001;
    private const DRAIN_SECONDS = 3.0;

    /** @var Channel<LogEntry|object>|null */
    private ?Channel $queue = null;
    /** @var Channel<bool>|null */
    private ?Channel $completion = null;
    /** @var Channel<bool>|null */
    private ?Channel $writerGate = null;
    private ?object $stopMarker = null;
    private int $reservedBytes = 0;
    private int $queuedEntries = 0;
    private int $pending = 0;
    private int $inflight = 0;
    private bool $accepting = true;
    private bool $draining = false;
    private bool $started = false;
    private bool $completed = false;
    private bool $fallbackReported = false;
    private int $fallbacks = 0;
    private int $writes = 0;
    private int $failures = 0;
    private int $droppedOnTimeout = 0;
    private ?int $writerOwner = null;
    private bool $consumerRunning = false;

    public function __construct(
        private readonly LoggerFactory $factory,
        private readonly LogConfig $config,
        private readonly RequestContext $requestContext,
    ) {}

    public function write(LogEntry $entry): void
    {
        $this->writeSerialized($entry);
    }

    public function submit(LogEntry $entry): void
    {
        if (! Coroutine::inCoroutine() || $this->config->writeMode() === WriteMode::SYNC) {
            $this->writeSerialized($entry);
            return;
        }

        $this->startIfNeeded();
        $this->startConsumer();
        ++$this->pending;
        if (! $this->consumerRunning || ! $this->accepting || $entry->estimatedBytes > $this->config->asyncMaxBufferBytes()) {
            ++$this->fallbacks;
            $this->reportFallbackOnce();
            try {
                $this->writeSerialized($entry);
            } finally {
                --$this->pending;
                $this->maybeComplete();
            }
            return;
        }

        if ($this->tryEnqueue($entry) || $this->waitAndRetry($entry)) {
            $this->startConsumer();
            return;
        }

        ++$this->fallbacks;
        $this->reportFallbackOnce();
        try {
            $this->writeSerialized($entry);
        } finally {
            --$this->pending;
            $this->maybeComplete();
        }
    }

    /** @return array{queued:int,queued_bytes:int,pending:int,inflight:int,fallbacks:int,writes:int,failures:int,dropped:int} */
    public function stats(): array
    {
        return [
            'queued' => $this->queuedEntries,
            'queued_bytes' => $this->reservedBytes,
            'pending' => $this->pending,
            'inflight' => $this->inflight,
            'fallbacks' => $this->fallbacks,
            'writes' => $this->writes,
            'failures' => $this->failures,
            'dropped' => $this->droppedOnTimeout,
        ];
    }

    private function startIfNeeded(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $capacity = max(1, intdiv($this->config->asyncMaxBufferBytes(), self::MIN_ENTRY_BYTES));
        $this->queue = new Channel($capacity);
        $this->completion = new Channel(1);
        $this->writerGate = new Channel(1);
        $this->writerGate->push(true);
        $this->stopMarker = new \stdClass();

        $this->startConsumer();
    }

    public function drain(): void
    {
        $this->beginDrain();
    }

    private function startConsumer(): void
    {
        if ($this->consumerRunning || $this->queue === null || $this->completed) {
            return;
        }
        try {
            $this->consumerRunning = Coroutine::create(function (): void {
                $this->consume();
            }) >= 0;
        } catch (Throwable) {
            $this->consumerRunning = false;
        }
    }

    private function tryEnqueue(LogEntry $entry): bool
    {
        if (! $this->accepting || $this->queue === null) {
            return false;
        }
        if ($this->reservedBytes + $entry->estimatedBytes > $this->config->asyncMaxBufferBytes()) {
            return false;
        }

        // No yield occurs between the reservation and the non-blocking push.
        $this->reservedBytes += $entry->estimatedBytes;
        ++$this->queuedEntries;
        if ($this->queue->push($entry, 0)) {
            return true;
        }
        --$this->queuedEntries;
        $this->reservedBytes -= $entry->estimatedBytes;
        return false;
    }

    private function waitAndRetry(LogEntry $entry): bool
    {
        Coroutine::sleep(self::ENQUEUE_WAIT_SECONDS);
        return $this->accepting && $this->tryEnqueue($entry);
    }

    private function consume(): void
    {
        try {
            while ($this->queue !== null) {
                $value = $this->queue->pop();
                if ($value === false) {
                    return;
                }
                if ($value === $this->stopMarker) {
                    if ($this->draining && $this->pending === 0) {
                        $this->completeDrain();
                        return;
                    }
                    continue;
                }
                if (! $value instanceof LogEntry) {
                    continue;
                }

                --$this->queuedEntries;
                $this->reservedBytes -= $value->estimatedBytes;
                ++$this->inflight;
                try {
                    $this->writeSerialized($value);
                } finally {
                    --$this->inflight;
                    --$this->pending;
                    $this->maybeComplete();
                }
            }
        } finally {
            $this->consumerRunning = false;
        }
    }

    private function beginDrain(): void
    {
        if ($this->completed || $this->draining || ! $this->started) {
            return;
        }
        $this->draining = true;
        $this->accepting = false;
        $this->startConsumer();
        if ($this->queue !== null && $this->stopMarker !== null) {
            $this->queue->push($this->stopMarker, 0);
        }
        if ($this->pending === 0) {
            $this->completeDrain();
            return;
        }

        $this->completion?->pop(self::DRAIN_SECONDS);
        if (! $this->completed) {
            $before = $this->stats();
            $this->dropQueuedEntries();
            $this->report(sprintf(
                'hyperf-log async drain timeout queued=%d queued_bytes=%d pending=%d inflight=%d dropped=%d request_id=%s',
                $before['queued'],
                $before['queued_bytes'],
                $before['pending'],
                $before['inflight'],
                $this->droppedOnTimeout,
                $this->requestContext->id() ?? 'unavailable',
            ));
            $this->completed = true;
            $this->queue?->close();
        }
    }

    private function dropQueuedEntries(): void
    {
        if ($this->queue === null) {
            return;
        }
        while (($value = $this->queue->pop(0)) !== false) {
            if ($value instanceof LogEntry) {
                --$this->queuedEntries;
                $this->reservedBytes -= $value->estimatedBytes;
                --$this->pending;
                ++$this->droppedOnTimeout;
            }
        }
    }

    private function maybeComplete(): void
    {
        if ($this->draining && $this->pending === 0 && $this->inflight === 0) {
            $this->completeDrain();
        }
    }

    private function completeDrain(): void
    {
        if ($this->completed) {
            return;
        }
        $this->completed = true;
        $this->completion?->push(true, 0);
        $this->queue?->close();
        $stats = $this->stats();
        $this->report(sprintf(
            'hyperf-log async drain complete queued=%d queued_bytes=%d pending=%d inflight=%d fallbacks=%d writes=%d failures=%d dropped=%d',
            $stats['queued'],
            $stats['queued_bytes'],
            $stats['pending'],
            $stats['inflight'],
            $stats['fallbacks'],
            $stats['writes'],
            $stats['failures'],
            $stats['dropped'],
        ));
    }

    private function writeSerialized(LogEntry $entry): void
    {
        $coroutineId = Coroutine::id();
        if ($this->writerOwner !== null && $this->writerOwner === $coroutineId) {
            ++$this->failures;
            $this->report(sprintf(
                'hyperf-log recursive handler write suppressed request_id=%s',
                $entry->metadata->requestId ?? 'unavailable',
            ));
            return;
        }
        $gate = $this->writerGate;
        if ($gate === null) {
            if (! Coroutine::inCoroutine()) {
                $this->writerOwner = $coroutineId;
                try {
                    $this->writeHandler($entry);
                } finally {
                    $this->writerOwner = null;
                }
                return;
            }
            $gate = $this->writerGate = new Channel(1);
            $gate->push(true);
        }
        $gate->pop();
        $this->writerOwner = $coroutineId;
        try {
            $this->writeHandler($entry);
        } finally {
            $this->writerOwner = null;
            $gate->push(true);
        }
    }

    private function writeHandler(LogEntry $entry): void
    {
        ++$this->writes;
        $context = $entry->context;
        $context[Collector::LOG_METADATA_KEY] = $entry->metadata;
        try {
            $logger = $this->factory->get($entry->metadata->collector->defaultChannel(), $this->config->loggerChannel());
            if ($logger instanceof MonologLogger) {
                $logger->addRecord($entry->level, $entry->metadata->collector->type(), $context, $entry->datetime);
            } else {
                $method = $entry->level->toPsrLogLevel();
                $logger->{$method}($entry->metadata->collector->type(), $context);
            }
        } catch (Throwable $exception) {
            ++$this->failures;
            $this->report(sprintf(
                'hyperf-log %s write failed: %s request_id=%s',
                $entry->metadata->collector->value,
                $exception::class,
                $entry->metadata->requestId ?? 'unavailable',
            ));
        }
    }

    private function reportFallbackOnce(): void
    {
        if ($this->fallbackReported) {
            return;
        }
        $this->fallbackReported = true;
        $this->report('hyperf-log async queue saturated; falling back to sync');
    }

    private function report(string $message): void
    {
        error_log($message);
    }
}

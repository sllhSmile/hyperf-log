<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;

/** Immutable event snapshot used by both synchronous and asynchronous writes. */
final readonly class LogEntry
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public LogMetadata $metadata,
        public Level $level,
        public array $context,
        public JsonSerializableDateTimeImmutable $datetime,
        public int $estimatedBytes,
    ) {}
}

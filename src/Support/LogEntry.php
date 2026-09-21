<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;

/**
 * 同步与异步写入共用的不可变提交单元。
 *
 * datetime 与 origin 均在提交方捕获，异步消费时不得改用消费协程的时间或 Context。
 * estimatedBytes 只用于队列预算，并非 PHP 对象的精确内存占用。
 */
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

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/**
 * PSR-7 Stream 的有界日志快照结果。
 *
 * contents 为 null 表示流不可安全读取、读取失败或内容超过上限；truncated 用于区分
 * 容量限制与普通不可用场景。该值对象不会持有 Stream，可安全地留在当前协程中处理。
 */
final readonly class StreamSnapshot
{
    public function __construct(
        public ?string $contents,
        public bool $truncated = false,
        public ?int $originalBytes = null,
    ) {
    }
}

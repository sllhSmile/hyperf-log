<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Context;

/** 当前执行单元不可变的链路快照；startedAt 使用 microtime(true) 的 Unix 秒数。 */
final readonly class TraceContext
{
    public function __construct(
        public string $requestId,
        public float $startedAt,
    ) {}
}

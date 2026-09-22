<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Context;

/** 当前执行单元不可变的链路快照；startedAt 使用 microtime(true) 的 Unix 秒数。 */
final readonly class TraceContext
{
    /** 固化链路 ID 与其启动时的 Unix 秒时间戳，供同一执行单元查询。 */
    public function __construct(
        public string $requestId,
        public float $startedAt,
    ) {}
}

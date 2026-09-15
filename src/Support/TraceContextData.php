<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/**
 * 当前执行单元不可分割的 trace 生命周期快照。
 *
 * 值对象保持只读，确保复制到日志子协程后不会被父协程或其他消费者修改。
 */
final readonly class TraceContextData
{
    public function __construct(
        public string $requestId,
        public float $startedAt,
    ) {
    }
}

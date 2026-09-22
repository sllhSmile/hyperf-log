<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * 提交日志时固化的内部元数据；Formatter 消费后不会输出内部标记本身。
 *
 * @internal
 */
final readonly class LogMetadata
{
    /** 将采集器身份与发起位置快照作为 Formatter 的内部标记。 */
    public function __construct(public Collector $collector, public LogOrigin $origin) {}
}

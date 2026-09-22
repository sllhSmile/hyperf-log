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
    public function __construct(public Collector $collector, public LogOrigin $origin) {}
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Contract;

use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * 四类采集器共用的日志写入边界。
 *
 * 实现应隔离内容处理和 Handler 异常，采集失败不得改变业务请求结果。
 */
interface CollectorLoggerInterface
{
    /** @param array<string, mixed> $context 已完成请求期资源快照的结构化上下文 */
    public function info(Collector $collector, array $context): void;
}

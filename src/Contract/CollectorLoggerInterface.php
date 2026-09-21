<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Contract;

use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogOrigin;

/**
 * 四类采集器共用的日志写入边界。
 *
 * 实现应隔离内容处理和 Handler 异常，采集失败不得改变业务请求结果。origin 省略时由
 * 实现捕获提交位置；可能跨协程完成的操作必须显式传入发起位置的 LogOrigin。
 */
interface CollectorLoggerInterface
{
    /**
     * @param array<string, mixed> $context 已完成请求期资源快照的结构化上下文
     * @param null|LogOrigin $origin 操作发起时的链路快照；异步完成场景不得重新读取当前 Context
     */
    public function emergency(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function alert(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function critical(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function error(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function warning(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function notice(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function info(Collector $collector, array $context, ?LogOrigin $origin = null): void;

    /** @param array<string, mixed> $context */
    public function debug(Collector $collector, array $context, ?LogOrigin $origin = null): void;
}

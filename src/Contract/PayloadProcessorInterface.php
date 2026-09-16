<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Contract;

use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * 定义日志上下文写入前的内容保护契约。
 *
 * CollectorLogger 会在 Formatter 和日志 IO 之前调用该处理器；协程环境中通常位于
 * 日志子协程，非协程环境则同步执行。实现类应基于传入快照返回处理后的新数组，不应
 * 修改调用方仍在使用的上下文，也不应读取 PSR-7 Stream 等请求期资源。
 *
 * 内置实现对 HTTP server/client 负载执行字段脱敏，并刻意让数据库 SQL 与 Redis
 * 格式化命令保持原文；四类日志都会应用容量限制。Redis AUTH 在监听器中强制遮蔽。
 */
interface PayloadProcessorInterface
{
    /**
     * @param Collector $collector 日志采集器
     * @param array<string, mixed> $context 已由业务协程完成请求/响应内容快照的日志上下文
     * @return array<string, mixed> 可安全交给 Formatter 和日志 Handler 的上下文副本
     */
    public function process(Collector $collector, array $context): array;
}

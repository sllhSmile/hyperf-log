<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Contract;

use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * 定义日志上下文写入前的内容保护契约。
 *
 * CollectorLogger 会在提交同步写入或异步队列之前，于调用日志方法的当前执行单元中
 * 同步调用该处理器。实现类只能处理已经完成的值快照，不应读取 PSR-7 Stream 等请求期
 * 资源；耗时操作也会直接增加业务协程提交日志的延迟。
 *
 * 内置实现对 HTTP server/client 负载执行字段脱敏，并刻意让数据库 SQL 与 Redis
 * 格式化命令保持原文；四类日志都会应用容量限制。Redis AUTH 在监听器中强制遮蔽。
 */
interface PayloadProcessorInterface
{
    /**
     * 在日志提交之前保护已完成快照的上下文，不读取请求期资源。
     *
     * @param Collector $collector 日志采集器
     * @param array<string, mixed> $context 已由调用方完成请求/响应资源快照的日志上下文
     * @return array<string, mixed> 可安全交给 Formatter 和日志 Handler 的上下文副本
     */
    public function process(Collector $collector, array $context): array;
}

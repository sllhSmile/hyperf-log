<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Contract;

/**
 * 定义日志上下文写入前的内容保护契约。
 *
 * LogWriter 会在 Formatter 和日志 IO 之前调用该处理器；HTTP/RPC 请求中通常位于
 * 日志子协程，CLI 场景则同步执行。实现类应基于传入快照返回处理后的新数组，不应
 * 修改调用方仍在使用的上下文，也不应读取 PSR-7 Stream 等请求期资源。
 *
 * 内置实现会处理 apilog、sdklog 和 redislog，并刻意让 dblog 保持原有 SQL 与
 * bindings 结构；替换实现时应自行决定是否延续该兼容行为。
 */
interface PayloadProcessorInterface
{
    /**
     * @param string $type 采集器名称，例如 apilog、sdklog 或 redislog
     * @param array<string, mixed> $context 已由业务协程完成请求/响应内容快照的日志上下文
     * @return array<string, mixed> 可安全交给 Formatter 和日志 Handler 的上下文副本
     */
    public function process(string $type, array $context): array;
}

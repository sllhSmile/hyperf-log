<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Logger\LoggerFactory;
use Throwable;

class LogWriter
{
    /**
     * @param LoggerFactory $factory Hyperf 日志记录器工厂
     * @param LogConfig $config 公共包配置读取器
     */
    public function __construct(private LoggerFactory $factory, private LogConfig $config)
    {
    }

    /**
     * 使用指定采集器的 logger channel 写入 INFO 级别结构化日志。
     *
     * @param string $type 采集器名称，例如 apilog 或 redislog
     * @param array<string, mixed> $context 日志上下文
     */
    public function info(string $type, array $context): void
    {
        // 在 HTTP/RPC 协程中将日志写入移到子协程，避免文件 IO 影响当前业务协程。
        if (Coroutine::inCoroutine()) {
            Coroutine::create(function () use ($type, $context): void {
                try {
                    $this->write($type, $context);
                } catch (Throwable) {
                    // 日志写入失败不能影响当前请求；异常由框架协程错误处理机制消费。
                }
            });

            return;
        }

        // CLI 通常不运行在协程中，采用同步兜底以保证命令结束前日志已经落盘。
        try {
            $this->write($type, $context);
        } catch (Throwable) {
            // 日志系统故障不能中断命令业务；同步路径同样保持“记录失败不影响主流程”。
        }
    }

    /**
     * 执行实际的 LoggerFactory 写入操作。
     *
     * @param string $type 采集器名称，例如 apilog 或 redislog
     * @param array<string, mixed> $context 已在父执行环境中构造完成的日志上下文
     */
    private function write(string $type, array $context): void
    {
        // 允许宿主应用用 channel 字段将采集器映射到自定义 logger channel。
        $channel = $this->config->channel($type);
        // Logger 名称与 channel 均采用采集器名称，便于 formatter 和日志平台过滤。
        $this->factory->get($channel, $channel)->info($type, $context);
    }
}

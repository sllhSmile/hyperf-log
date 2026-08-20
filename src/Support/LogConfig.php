<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Contract\ConfigInterface;

/**
 * 日志公共包的配置读取器。
 *
 * 日志 channel 使用 Hyperf 新式 logger.channels 配置。请求链路字段和 Guzzle
 * 默认参数则只读取公共包发布的 trace_log 独立配置。
 */
class LogConfig
{
    /**
     * 支持的日志采集器及对应的 logger channel 名称。
     *
     * @var string[]
     */
    private const COLLECTORS = ['apilog', 'redislog', 'dblog', 'sdklog'];

    /**
     * @param ConfigInterface $config Hyperf 配置仓库
     */
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    /**
     * 判断指定采集器是否启用。
     *
     * 所有 channel 均位于 logger.channels 下，避免旧式配置在 LoggerFactory 初始化
     * 前后产生不同的读取路径。
     */
    public function enabled(string $channel): bool
    {
        return (bool) $this->channelConfig($channel, 'enabled', false);
    }

    /**
     * 判断是否至少启用了一个采集器，用于决定是否初始化请求上下文。
     */
    public function anyEnabled(): bool
    {
        foreach (self::COLLECTORS as $channel) {
            if ($this->enabled($channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 返回日志 channel 名称。
     *
     * 默认使用采集器名称，同时允许宿主项目通过 `channel` 配置映射到自定义 channel。
     */
    public function channel(string $channel): string
    {
        return (string) $this->channelConfig($channel, 'channel', $channel);
    }

    /**
     * 判断指定采集器是否应记录响应数据。
     *
     * dblog 的 result 和 sdklog 的响应体可能很大，因此默认 false。
     */
    public function responseEnabled(string $channel): bool
    {
        // 从 channel 自身读取开关，确保 dblog 和 sdklog 的大响应可独立控制。
        return (bool) $this->channelConfig($channel, 'response_enabled', false);
    }

    /**
     * 获取入站和出站请求透传的 request-id HTTP header 名称。
     */
    public function requestIdHeader(): string
    {
        return (string) $this->config->get('trace_log.request_id_header', 'x-request-id');
    }

    /**
     * 获取 request-id 在协程上下文中的键名。
     */
    public function requestIdContextKey(): string
    {
        return (string) $this->config->get('trace_log.request_id_context_key', 'x_request_id');
    }

    /**
     * 获取 Guzzle 出站请求开始时间 Header 名称。
     */
    public function requestStartHeader(): string
    {
        return (string) $this->config->get('trace_log.request_start_header', 'x-request-start-time');
    }

    /**
     * 获取 HTTP 入站请求开始时间在协程上下文中的键名。
     */
    public function requestStartContextKey(): string
    {
        return (string) $this->config->get('trace_log.request_start_context_key', 'x_request_start_time');
    }

    /**
     * 获取 Guzzle 请求的默认总超时时间，单位：秒。
     */
    public function guzzleTimeout(): float
    {
        return (float) $this->config->get('trace_log.guzzle.timeout', 10);
    }

    /**
     * 获取 Guzzle 建立连接的默认超时时间，单位：秒。
     */
    public function guzzleConnectTimeout(): float
    {
        return (float) $this->config->get('trace_log.guzzle.connect_timeout', 10);
    }

    /**
     * 获取当前应用名称，用于统一记录 API、Redis、数据库和 SDK 日志。
     */
    public function appName(): string
    {
        return (string) $this->config->get('app_name', $this->config->get('app_env', ''));
    }

    /**
     * 从 Hyperf 新式 logger.channels 配置中读取 channel 字段。
     *
     * @param mixed $default 当 channel 或字段未配置时返回的默认值
     */
    private function channelConfig(string $channel, string $key, mixed $default): mixed
    {
        // 所有 channel 都在 channels 下，配置路径不随 LoggerFactory 初始化顺序变化。
        return $this->config->get("logger.channels.{$channel}.{$key}", $default);
    }
}

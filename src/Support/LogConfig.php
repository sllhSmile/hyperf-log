<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Contract\ConfigInterface;
use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * trace_log 配置的类型化读取边界。
 *
 * 采集器默认全部关闭；API 响应默认开启，其余响应默认关闭。可能导致保护失效的 payload
 * 配置采用 fail-fast 校验，避免静默回退到不安全值。
 */
final readonly class LogConfig
{
    private const DEFAULT_SENSITIVE_FIELDS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key',
        'password', 'passwd', 'token', 'access_token', 'refresh_token', 'api_key',
        'api-key', 'secret', 'client_secret',
    ];

    public function __construct(private ConfigInterface $config) {}

    public function enabled(Collector $collector): bool
    {
        return (bool) $this->collectorValue($collector, 'enabled', false);
    }

    public function anyEnabled(): bool
    {
        foreach (Collector::cases() as $collector) {
            if ($this->enabled($collector)) {
                return true;
            }
        }

        return false;
    }

    public function loggerChannel(): ?string
    {
        $channel = $this->config->get('trace_log.logger_channel');
        if ($channel === null) {
            return null;
        }
        if (! is_string($channel) || trim($channel) === '') {
            throw new \InvalidArgumentException('trace_log.logger_channel must be a non-empty string or null.');
        }

        return trim($channel);
    }

    public function responseEnabled(Collector $collector): bool
    {
        return (bool) $this->collectorValue($collector, 'response_enabled', $collector === Collector::Api);
    }

    public function requestIdHeader(): string
    {
        // PSR-7 Header 大小写不敏感，统一小写可稳定日志字段和测试输出。
        return strtolower((string) $this->config->get('trace_log.request_id_header', 'x-b3-traceid'));
    }

    public function service(): string
    {
        return (string) $this->config->get('app_name', $this->config->get('app_env', ''));
    }

    /** @return string[] */
    public function payloadSensitiveFields(): array
    {
        $fields = $this->config->get('trace_log.payload.sensitive_fields', self::DEFAULT_SENSITIVE_FIELDS);
        if (! is_array($fields)) {
            throw new \InvalidArgumentException('trace_log.payload.sensitive_fields must be an array.');
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (! is_string($field) || trim($field) === '') {
                throw new \InvalidArgumentException('trace_log.payload.sensitive_fields must contain non-empty strings.');
            }
            $normalized[] = strtolower(trim($field));
        }

        return array_values(array_unique($normalized));
    }

    public function payloadRedactionValue(): string
    {
        $replacement = $this->config->get('trace_log.payload.redaction_value', '****');
        if (! is_string($replacement)) {
            throw new \InvalidArgumentException('trace_log.payload.redaction_value must be a string.');
        }

        return $replacement;
    }

    public function payloadMaxBytes(): ?int
    {
        $maxBytes = $this->config->get('trace_log.payload.max_bytes', 64 * 1024);
        if ($maxBytes === null) {
            return null;
        }
        if (! is_int($maxBytes) || $maxBytes <= 0) {
            throw new \InvalidArgumentException('trace_log.payload.max_bytes must be a positive integer or null.');
        }

        return $maxBytes;
    }

    private function collectorValue(Collector $collector, string $key, mixed $default): mixed
    {
        return $this->config->get("trace_log.collectors.{$collector->value}.{$key}", $default);
    }

}

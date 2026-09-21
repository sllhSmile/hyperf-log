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
    public const DEFAULT_ASYNC_MAX_BUFFER_BYTES = 8 * 1024 * 1024;

    private const WRITE_MODES = [WriteMode::ASYNC, WriteMode::SYNC];

    public const DEFAULT_SENSITIVE_FIELDS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key',
        'password', 'passwd', 'token', 'access_token', 'refresh_token', 'api_key',
        'api-key', 'secret', 'client_secret',
    ];

    public function __construct(private ConfigInterface $config)
    {
        foreach (Collector::cases() as $collector) {
            $this->enabled($collector);
            $this->responseEnabled($collector);
        }
        $this->requestIdHeader();
    }

    public function enabled(Collector $collector): bool
    {
        return $this->collectorBoolean($collector, 'enabled', false);
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

    public function writeMode(): string
    {
        $mode = $this->config->get('trace_log.write_mode', WriteMode::SYNC);
        if (! is_string($mode) || ! in_array($mode, self::WRITE_MODES, true)) {
            throw new \InvalidArgumentException('trace_log.write_mode must be either "async" or "sync".');
        }

        return $mode;
    }

    public function asyncMaxBufferBytes(): int
    {
        $bytes = $this->config->get('trace_log.async.max_buffer_bytes', self::DEFAULT_ASYNC_MAX_BUFFER_BYTES);
        if (! is_int($bytes) || $bytes < 1024) {
            throw new \InvalidArgumentException('trace_log.async.max_buffer_bytes must be an integer greater than or equal to 1024.');
        }

        return $bytes;
    }

    public function responseEnabled(Collector $collector): bool
    {
        return $this->collectorBoolean($collector, 'response_enabled', $collector === Collector::Api);
    }

    public function requestIdHeader(): string
    {
        $header = $this->config->get('trace_log.request_id_header', 'x-b3-traceid');
        if (! is_string($header) || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $header) !== 1) {
            throw new \InvalidArgumentException('trace_log.request_id_header must be a valid non-empty HTTP header name.');
        }

        // PSR-7 Header 大小写不敏感，统一小写可稳定日志字段和测试输出。
        return strtolower($header);
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

    private function collectorBoolean(Collector $collector, string $key, bool $default): bool
    {
        $value = $this->collectorValue($collector, $key, $default);
        if (! is_bool($value)) {
            throw new \InvalidArgumentException(sprintf(
                'trace_log.collectors.%s.%s must be a boolean.',
                $collector->value,
                $key,
            ));
        }

        return $value;
    }

}

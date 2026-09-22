<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Contract\ConfigInterface;
use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * trace_log 配置的类型化读取边界。
 *
 * 采集器默认全部关闭；API 响应默认开启，其余响应默认关闭。所有已知配置都在构造时
 * 严格校验并保存为不可变快照；非法类型会抛出异常，不做宽松类型转换。配置文件变更
 * 需要重启 Worker 才会生效，与 Hyperf 常驻内存的运行模型保持一致。
 */
final readonly class LogConfig
{
    public const DEFAULT_ASYNC_MAX_BUFFER_BYTES = 8 * 1024 * 1024;
    public const DEFAULT_COLLECTOR_ENABLED = false;
    public const DEFAULT_LOGGER_CHANNEL = null;
    public const DEFAULT_PAYLOAD_MAX_BYTES = 64 * 1024;
    public const DEFAULT_REDACTION_VALUE = '****';
    public const DEFAULT_REQUEST_ID_HEADER = 'x-b3-traceid';
    public const DEFAULT_RESPONSE_ENABLED = false;
    public const DEFAULT_WRITE_MODE = WriteMode::SYNC;
    public const DEFAULT_API_RESPONSE_ENABLED = true;

    private const WRITE_MODES = [WriteMode::ASYNC, WriteMode::SYNC];

    public const DEFAULT_SENSITIVE_FIELDS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key',
        'password', 'passwd', 'token', 'access_token', 'refresh_token', 'api_key',
        'api-key', 'secret', 'client_secret',
    ];

    /** @var array<string, bool> */
    private array $collectorEnabled;
    /** @var array<string, bool> */
    private array $collectorResponseEnabled;
    private ?string $loggerChannel;
    private string $writeMode;
    private int $asyncMaxBufferBytes;
    private string $requestIdHeader;
    private string $service;
    /** @var list<string> */
    private array $payloadSensitiveFields;
    private string $payloadRedactionValue;
    private ?int $payloadMaxBytes;

    /** 一次性读取并校验 Hyperf 配置，后续访问只返回不可变快照。 */
    public function __construct(ConfigInterface $config)
    {
        $collectorEnabled = [];
        $collectorResponseEnabled = [];
        foreach (Collector::cases() as $collector) {
            $collectorEnabled[$collector->value] = $this->readCollectorBoolean(
                $config,
                $collector,
                'enabled',
                self::DEFAULT_COLLECTOR_ENABLED,
            );
            $collectorResponseEnabled[$collector->value] = $this->readCollectorBoolean(
                $config,
                $collector,
                'response_enabled',
                $collector === Collector::Api ? self::DEFAULT_API_RESPONSE_ENABLED : self::DEFAULT_RESPONSE_ENABLED,
            );
        }

        $this->collectorEnabled = $collectorEnabled;
        $this->collectorResponseEnabled = $collectorResponseEnabled;
        $this->loggerChannel = $this->readLoggerChannel($config);
        $this->writeMode = $this->readWriteMode($config);
        $this->asyncMaxBufferBytes = $this->readAsyncMaxBufferBytes($config);
        $this->requestIdHeader = $this->readRequestIdHeader($config);
        $this->service = $this->readService($config);
        $this->payloadSensitiveFields = $this->readPayloadSensitiveFields($config);
        $this->payloadRedactionValue = $this->readPayloadRedactionValue($config);
        $this->payloadMaxBytes = $this->readPayloadMaxBytes($config);
    }

    /** 返回指定采集器的严格布尔开关。 */
    public function enabled(Collector $collector): bool
    {
        return $this->collectorEnabled[$collector->value];
    }

    /** 判断当前是否至少启用了一个采集器。 */
    public function anyEnabled(): bool
    {
        foreach (Collector::cases() as $collector) {
            if ($this->enabled($collector)) {
                return true;
            }
        }

        return false;
    }

    /** 返回 trim 后的宿主 logger channel；null 表示跟随 logger.default。 */
    public function loggerChannel(): ?string
    {
        return $this->loggerChannel;
    }

    /** 返回严格匹配 sync 或 async 的写入模式。 */
    public function writeMode(): string
    {
        return $this->writeMode;
    }

    /** 返回每 Worker 异步队列的估算字节预算。 */
    public function asyncMaxBufferBytes(): int
    {
        return $this->asyncMaxBufferBytes;
    }

    /** 返回指定采集器是否记录响应详情；API 默认开启，其余默认关闭。 */
    public function responseEnabled(Collector $collector): bool
    {
        return $this->collectorResponseEnabled[$collector->value];
    }

    /** 校验并规范化用于入站和出站透传的 request-id Header 名称。 */
    public function requestIdHeader(): string
    {
        return $this->requestIdHeader;
    }

    /** 返回日志 service 名称，优先使用 app_name，缺失时回退 app_env。 */
    public function service(): string
    {
        return $this->service;
    }

    /** 返回规范化为小写并去重的敏感字段名。
     * @return list<string>
     */
    public function payloadSensitiveFields(): array
    {
        return $this->payloadSensitiveFields;
    }

    /** 返回命中敏感字段时使用的替换文本。 */
    public function payloadRedactionValue(): string
    {
        return $this->payloadRedactionValue;
    }

    /** 返回单字段字节上限；null 表示禁用容量限制。 */
    public function payloadMaxBytes(): ?int
    {
        return $this->payloadMaxBytes;
    }

    /** 读取并规范化宿主 logger channel。 */
    private function readLoggerChannel(ConfigInterface $config): ?string
    {
        $channel = $config->get('trace_log.logger_channel', self::DEFAULT_LOGGER_CHANNEL);
        if ($channel === null) {
            return null;
        }
        if (! is_string($channel) || trim($channel) === '') {
            throw new \InvalidArgumentException('trace_log.logger_channel must be a non-empty string or null.');
        }

        return trim($channel);
    }

    /** 读取并校验同步或异步写入模式。 */
    private function readWriteMode(ConfigInterface $config): string
    {
        $mode = $config->get('trace_log.write_mode', self::DEFAULT_WRITE_MODE);
        if (! is_string($mode) || ! in_array($mode, self::WRITE_MODES, true)) {
            throw new \InvalidArgumentException('trace_log.write_mode must be either "async" or "sync".');
        }

        return $mode;
    }

    /** 读取并校验每 Worker 异步队列的估算字节预算。 */
    private function readAsyncMaxBufferBytes(ConfigInterface $config): int
    {
        $bytes = $config->get('trace_log.async.max_buffer_bytes', self::DEFAULT_ASYNC_MAX_BUFFER_BYTES);
        if (! is_int($bytes) || $bytes < 1024) {
            throw new \InvalidArgumentException('trace_log.async.max_buffer_bytes must be an integer greater than or equal to 1024.');
        }

        return $bytes;
    }

    /** 读取并规范化用于入站和出站透传的 request-id Header 名称。 */
    private function readRequestIdHeader(ConfigInterface $config): string
    {
        $header = $config->get('trace_log.request_id_header', self::DEFAULT_REQUEST_ID_HEADER);
        if (! is_string($header) || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $header) !== 1) {
            throw new \InvalidArgumentException('trace_log.request_id_header must be a valid non-empty HTTP header name.');
        }

        // PSR-7 Header 大小写不敏感，统一小写可稳定日志字段和测试输出。
        return strtolower($header);
    }

    /** 读取日志 service 名称，优先使用 app_name，缺失时回退 app_env。 */
    private function readService(ConfigInterface $config): string
    {
        $service = $config->get('app_name', $config->get('app_env', ''));
        if (! is_string($service)) {
            throw new \InvalidArgumentException('app_name must be a string.');
        }

        return $service;
    }

    /**
     * 读取敏感字段名，并规范化为小写去重列表。
     * @return list<string>
     */
    private function readPayloadSensitiveFields(ConfigInterface $config): array
    {
        $fields = $config->get('trace_log.payload.sensitive_fields', self::DEFAULT_SENSITIVE_FIELDS);
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

    /** 读取命中敏感字段时使用的替换文本。 */
    private function readPayloadRedactionValue(ConfigInterface $config): string
    {
        $replacement = $config->get('trace_log.payload.redaction_value', self::DEFAULT_REDACTION_VALUE);
        if (! is_string($replacement)) {
            throw new \InvalidArgumentException('trace_log.payload.redaction_value must be a string.');
        }

        return $replacement;
    }

    /** 读取并校验单字段字节上限；null 表示禁用容量限制。 */
    private function readPayloadMaxBytes(ConfigInterface $config): ?int
    {
        $maxBytes = $config->get('trace_log.payload.max_bytes', self::DEFAULT_PAYLOAD_MAX_BYTES);
        if ($maxBytes === null) {
            return null;
        }
        if (! is_int($maxBytes) || $maxBytes <= 0) {
            throw new \InvalidArgumentException('trace_log.payload.max_bytes must be a positive integer or null.');
        }

        return $maxBytes;
    }

    /** 读取严格布尔采集器配置，拒绝字符串和数字的宽松转换。 */
    private function readCollectorBoolean(
        ConfigInterface $config,
        Collector $collector,
        string $key,
        bool $default,
    ): bool {
        $value = $config->get("trace_log.collectors.{$collector->value}.{$key}", $default);
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

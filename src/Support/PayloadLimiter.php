<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Enum\PayloadAction;
use Sllhsmile\HyperfLog\Enum\PayloadReason;

/**
 * 对各采集器约定的大字段逐字段执行统一字节上限。
 *
 * 文本保留 UTF-8 安全预览并标记 truncated；数组等结构化值整体省略，避免截断后产生
 * 看似完整但语义错误的数据。该限制不是整条日志的总大小上限；每次保护动作都会追加到
 * payload_protection。
 */
final readonly class PayloadLimiter
{
    public function __construct(private LogConfig $config) {}

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function limit(Collector $collector, array $context): array
    {
        $limit = $this->config->payloadMaxBytes();
        if ($limit === null) {
            return $context;
        }
        $paths = match ($collector) {
            Collector::Api, Collector::Sdk => ['request.body', 'request.files', 'response.body'],
            Collector::Database => ['request.sql', 'response.body'],
            Collector::Redis => ['request.command', 'response.body'],
        };
        foreach ($paths as $path) {
            $this->limitPath($context, $path, $limit);
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function limitPath(array &$context, string $path, int $limit): void
    {
        $segments = explode('.', $path);
        $value = &$context;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return;
            }
            $value = &$value[$segment];
        }
        $encoded = is_string($value) ? $value : json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if (! is_string($encoded) || strlen($encoded) <= $limit) {
            return;
        }

        $originalBytes = strlen($encoded);
        $action = is_string($value) ? PayloadAction::Truncated : PayloadAction::Omitted;
        if (is_string($value)) {
            $value = $this->truncate($value, $limit);
        } else {
            $parent = &$context;
            foreach (array_slice($segments, 0, -1) as $segment) {
                $parent = &$parent[$segment];
            }
            unset($parent[$segments[array_key_last($segments)]]);
        }
        $context['payload_protection'][] = (new PayloadProtection(
            $path,
            $action,
            PayloadReason::LimitExceeded,
            $limit,
            $originalBytes,
        ))->toArray();
    }

    private function truncate(string $value, int $limit): string
    {
        // 非 UTF-8 二进制先转为可安全写入 JSON 的文本，再执行同一字节限制。
        if (preg_match('//u', $value) !== 1) {
            $value = 'base64:' . base64_encode($value);
        }
        if ($limit <= 3) {
            return substr('...', 0, $limit);
        }
        $preview = function_exists('mb_strcut')
            ? mb_strcut($value, 0, $limit - 3, 'UTF-8')
            : substr($value, 0, $limit - 3);
        while ($preview !== '' && preg_match('//u', $preview) !== 1) {
            $preview = substr($preview, 0, -1);
        }

        return $preview . '...';
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use JsonException;
use Sllhsmile\HyperfLog\Enum\PayloadAction;
use Sllhsmile\HyperfLog\Enum\PayloadReason;

/**
 * 按字段名处理 HTTP server/client 的 Header、URL query 和结构化 body。
 *
 * 字段匹配不区分大小写。显式 JSON 无法解析时采用 fail-closed：省略原正文并写入
 * payload_protection，避免格式错误的敏感内容绕过结构化脱敏。表单与 JSON 字符串会
 * 解析后处理；其他明确 Content-Type 的纯文本或二进制正文不会按内容猜测字段，只应用
 * 后续容量限制。
 */
final readonly class PayloadRedactor
{
    public function __construct(private LogConfig $config) {}

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        $fields = array_fill_keys($this->config->payloadSensitiveFields(), true);
        $replacement = $this->config->payloadRedactionValue();
        $requestType = $this->contentType($context['request'] ?? null);
        $responseType = $this->contentType($context['response'] ?? null);
        $requestHeaders = $this->headers($context['request'] ?? null, $fields, $replacement);
        $responseHeaders = $this->headers($context['response'] ?? null, $fields, $replacement);

        $context = $this->redactArray($context, $fields, $replacement);
        if ($requestHeaders !== null) {
            $context['request']['headers'] = $requestHeaders;
        }
        if ($responseHeaders !== null) {
            $context['response']['headers'] = $responseHeaders;
        }
        $this->redactUrl($context, $fields, $replacement);
        $this->redactBody($context, 'request', $requestType, $fields, $replacement);
        $this->redactBody($context, 'response', $responseType, $fields, $replacement);

        return $context;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true> $fields
     * @return array<array-key, mixed>
     */
    private function redactArray(array $value, array $fields, string $replacement): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = is_string($key) ? strtolower($key) : null;
            if ($normalized !== null && isset($fields[$normalized])) {
                $result[$key] = $replacement;
                continue;
            }
            $result[$key] = is_array($item) ? $this->redactArray($item, $fields, $replacement) : $item;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @param array<string, true> $fields
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers, array $fields, string $replacement): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            $result[$name] = isset($fields[$name])
                ? (is_array($value) ? array_fill(0, count($value), $replacement) : [$replacement])
                : (is_array($value) ? array_values($value) : [(string) $value]);
        }

        return $result;
    }

    /**
     * @param array<string, true> $fields
     * @return array<string, mixed>|null
     */
    private function headers(mixed $message, array $fields, string $replacement): ?array
    {
        if (! is_array($message) || ! is_array($message['headers'] ?? null)) {
            return null;
        }

        return $this->redactHeaders($message['headers'], $fields, $replacement);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, true> $fields
     */
    private function redactUrl(array &$context, array $fields, string $replacement): void
    {
        $url = $context['request']['url'] ?? null;
        if (! is_string($url) || $fields === [] || ! str_contains($url, '?')) {
            return;
        }
        $urlParts = explode('#', $url, 2);
        $beforeFragment = $urlParts[0];
        $fragment = $urlParts[1] ?? null;
        [$base, $query] = array_pad(explode('?', $beforeFragment, 2), 2, '');
        $context['request']['url'] = $base . '?' . $this->redactQuery($query, $fields, $replacement)
            . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, true> $fields
     */
    private function redactBody(array &$context, string $message, string $contentType, array $fields, string $replacement): void
    {
        $body = $context[$message]['body'] ?? null;
        if (! is_string($body) || $body === '') {
            return;
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            $context[$message]['body'] = $this->redactQuery($body, $fields, $replacement);

            return;
        }
        if ($contentType !== '' && ! $this->isJson($contentType)) {
            return;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($this->isJson($contentType)) {
                // 声明为 JSON 的正文无法可靠识别字段边界，不能退回记录原始文本。
                unset($context[$message]['body']);
                $context['payload_protection'][] = (new PayloadProtection(
                    "{$message}.body",
                    PayloadAction::Omitted,
                    PayloadReason::InvalidJson,
                ))->toArray();
            }

            return;
        }
        $context[$message]['body'] = is_array($decoded)
            ? $this->redactArray($decoded, $fields, $replacement)
            : $decoded;
    }

    /** @param array<string, true> $fields */
    private function redactQuery(string $query, array $fields, string $replacement): string
    {
        $segments = preg_split('/([&;])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            return $query;
        }
        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '&' || $segment === ';') {
                continue;
            }
            [$key] = explode('=', $segment, 2);
            preg_match_all('/[^\[\]]+/', strtolower(urldecode($key)), $matches);
            if (array_intersect(array_keys($fields), $matches[0]) !== []) {
                $segments[$index] = $key . '=' . rawurlencode($replacement);
            }
        }

        return implode('', $segments);
    }

    private function contentType(mixed $message): string
    {
        if (! is_array($message) || ! is_array($message['headers'] ?? null)) {
            return '';
        }
        foreach ($message['headers'] as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                $line = is_array($value) ? implode(',', $value) : (string) $value;

                return strtolower(trim(explode(';', $line, 2)[0]));
            }
        }

        return '';
    }

    private function isJson(string $contentType): bool
    {
        return $contentType === 'application/json' || str_ends_with($contentType, '+json');
    }
}

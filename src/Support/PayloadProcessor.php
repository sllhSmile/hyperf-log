<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;

/**
 * 对 API、Guzzle 和 Redis 日志执行字段脱敏与容量限制。
 *
 * 处理顺序固定为“结构化字段脱敏 -> 请求 URL/Body 脱敏与 JSON 解析 -> 大字段截断”，
 * 避免截断后失去结构而无法继续识别敏感字段。处理器由 LogWriter 在日志子协程内调用；
 * dblog 不参与脱敏，但仍会执行容量限制。
 *
 * 入站 multipart/form-data 由 ApiLogListener 在当前请求协程中转换为字段和文件元数据；
 * sdklog 的出站 multipart 及其他原始文本只应用容量限制。Redis 的格式化 command
 * 字符串也不会被重新解析，调用方不应将这些未结构化内容视为已经完成字段级脱敏。
 */
class PayloadProcessor implements PayloadProcessorInterface
{
    public function __construct(private readonly LogConfig $config)
    {
    }

    public function process(string $type, array $context): array
    {
        if ($type !== 'dblog') {
            $fields = array_fill_keys(array_map('strtolower', $this->config->payloadSensitiveFields()), true);
            $replacement = $this->config->payloadRedactionValue();

            // 先递归处理已结构化的 Header、multipart 字段及响应数组。
            $context = $this->redactArray($context, $fields, $replacement);
            // 只有 HTTP 采集器才按 Content-Type 解释字符串 Body；Redis 字符串结果即使
            // 内容恰好是 JSON，也仍应保留 Redis 返回的字符串类型。
            if (in_array($type, ['apilog', 'sdklog'], true)) {
                $this->redactRequest($context, $fields, $replacement);
                $this->redactResponse($context, $fields, $replacement);
            }
        }

        // 脱敏必须早于截断，否则被截成预览字符串后将无法再可靠识别字段名。
        $this->truncatePayloads($type, $context);

        return $context;
    }

    /**
     * 按字段名递归遮蔽结构化数组；字段匹配不区分大小写。
     *
     * @param array<array-key, mixed> $value
     * @param array<string, true> $fields
     * @return array<array-key, mixed>
     */
    private function redactArray(array $value, array $fields, string $replacement): array
    {
        if ($fields === []) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : null;
            if ($normalizedKey !== null && isset($fields[$normalizedKey])) {
                $result[$key] = $replacement;
                continue;
            }

            // PSR-7 Header 值使用 string[]；保留数组形态，避免脱敏改变日志字段结构。
            if ($normalizedKey === 'headers' && is_array($item)) {
                $result[$key] = $this->redactHeaders($item, $fields, $replacement);
                continue;
            }

            $result[$key] = is_array($item)
                ? $this->redactArray($item, $fields, $replacement)
                : $item;
        }

        return $result;
    }

    /**
     * 遮蔽 Header 时保留 PSR-7 的多值数组形态和元素数量。
     *
     * @param array<string, mixed> $headers
     * @param array<string, true> $fields
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers, array $fields, string $replacement): array
    {
        foreach ($headers as $name => $value) {
            if (! isset($fields[strtolower((string) $name)])) {
                continue;
            }

            $headers[$name] = is_array($value)
                ? array_fill(0, count($value), $replacement)
                : $replacement;
        }

        return $headers;
    }

    /**
     * 处理 request.url 和已快照为字符串的 request.body。
     *
     * JSON 在 Content-Type 缺失时兼容按内容识别，显式声明其他媒体类型时保持原字符串；
     * 表单字符串只有在明确声明为 application/x-www-form-urlencoded 时才处理。
     *
     * @param array<string, mixed> $context
     * @param array<string, true> $fields
     */
    private function redactRequest(array &$context, array $fields, string $replacement): void
    {
        if (! isset($context['request']) || ! is_array($context['request'])) {
            return;
        }

        $request = &$context['request'];
        if ($fields !== [] && isset($request['url']) && is_string($request['url'])) {
            $request['url'] = $this->redactUrl($request['url'], $fields, $replacement);
        }

        if (! isset($request['body']) || ! is_string($request['body']) || $request['body'] === '') {
            return;
        }

        if ($this->shouldDecodeJson($request)) {
            $decoded = json_decode($request['body'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // JSON 对象和数组保留结构，避免 Formatter 再次转义为 JSON 字符串。
                $request['body'] = $this->redactArray($decoded, $fields, $replacement);

                return;
            }
        }

        if ($fields !== [] && $this->contentType($request) === 'application/x-www-form-urlencoded') {
            $request['body'] = $this->redactQueryString($request['body'], $fields, $replacement);
        }

        // 入站 multipart/form-data 已由 ApiLogListener 转换为数组；出站 multipart 和
        // 其他非 JSON 原始文本保持原值并仅执行容量限制，避免不完整解析破坏日志内容。
    }

    /**
     * 对字符串形式的 JSON 响应执行字段脱敏；非 JSON 响应保持原样。
     *
     * @param array<string, mixed> $context
     * @param array<string, true> $fields
     */
    private function redactResponse(array &$context, array $fields, string $replacement): void
    {
        $response = $context['response'] ?? null;
        if (! is_array($response) || ! $this->shouldDecodeJson($response)) {
            return;
        }

        $body = $response['body'] ?? null;
        if (! is_string($body) || $body === '') {
            return;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return;
        }

        // JSON 响应同样保留结构；普通文本响应继续保持原字符串。
        $context['response']['body'] = $this->redactArray($decoded, $fields, $replacement);
    }

    /**
     * 从 Header 中提取不带 charset、boundary 等参数的媒体类型。
     *
     * @param array<string, mixed> $message
     */
    private function contentType(array $message): string
    {
        if (! isset($message['headers']) || ! is_array($message['headers'])) {
            return '';
        }

        foreach ($message['headers'] as $name => $value) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }

            $line = is_array($value) ? implode(',', $value) : (string) $value;

            return strtolower(trim(explode(';', $line, 2)[0]));
        }

        return '';
    }

    /**
     * Header 缺失时兼容按内容识别 JSON；显式声明其他媒体类型时尊重发送方格式。
     *
     * @param array<string, mixed> $message
     */
    private function shouldDecodeJson(array $message): bool
    {
        $contentType = $this->contentType($message);

        return $contentType === ''
            || $contentType === 'application/json'
            || str_ends_with($contentType, '+json');
    }

    /**
     * 只改写 URL 查询部分，保留路径和 fragment 原样。
     *
     * @param array<string, true> $fields
     */
    private function redactUrl(string $url, array $fields, string $replacement): string
    {
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);
        $query = $fragmentStart === false
            ? substr($url, $queryStart + 1)
            : substr($url, $queryStart + 1, $fragmentStart - $queryStart - 1);
        $suffix = $fragmentStart === false ? '' : substr($url, $fragmentStart);

        return substr($url, 0, $queryStart + 1)
            . $this->redactQueryString($query, $fields, $replacement)
            . $suffix;
    }

    /**
     * 保留参数顺序、重复键和原始编码，只替换敏感参数的值。
     *
     * @param array<string, true> $fields
     */
    private function redactQueryString(string $query, array $fields, string $replacement): string
    {
        $segments = preg_split('/([&;])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            return $query;
        }

        foreach ($segments as $index => $segment) {
            if ($segment === '&' || $segment === ';' || $segment === '') {
                continue;
            }

            [$rawKey] = explode('=', $segment, 2);
            if (! $this->isSensitiveQueryKey(urldecode($rawKey), $fields)) {
                continue;
            }

            $segments[$index] = $rawKey . '=' . rawurlencode($replacement);
        }

        return implode('', $segments);
    }

    /**
     * 将 foo[token] 这类括号路径拆成字段段，任一段命中即视为敏感参数。
     *
     * @param array<string, true> $fields
     */
    private function isSensitiveQueryKey(string $key, array $fields): bool
    {
        preg_match_all('/[^\[\]]+/', strtolower($key), $matches);
        foreach ($matches[0] as $part) {
            if (isset($fields[$part])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 仅限制各采集器中可能快速膨胀的负载字段，基础定位字段保持完整。
     *
     * apilog/sdklog 限制请求体、上传文件元数据和响应体；redislog 限制完整命令和结果；
     * dblog 限制完整 SQL 和结果。发生截断时，payload_truncation 会记录字段路径和容量信息。
     *
     * @param array<string, mixed> $context
     */
    private function truncatePayloads(string $type, array &$context): void
    {
        $maxBytes = $this->config->payloadMaxBytes();
        if ($maxBytes === null) {
            return;
        }

        $truncation = isset($context['payload_truncation']) && is_array($context['payload_truncation'])
            ? $context['payload_truncation']
            : [];
        if (in_array($type, ['apilog', 'sdklog'], true)) {
            $this->truncateNestedField($context, ['request', 'body'], 'request.body', $maxBytes, $truncation);
            $this->omitNestedField($context, ['request', 'files'], 'request.files', $maxBytes, $truncation);
            $this->truncateNestedField($context, ['response', 'body'], 'response.body', $maxBytes, $truncation);
        } elseif ($type === 'redislog') {
            $this->truncateNestedField($context, ['request', 'command'], 'request.command', $maxBytes, $truncation);
            $this->truncateNestedField($context, ['response', 'body'], 'response.body', $maxBytes, $truncation);
        } elseif ($type === 'dblog') {
            $this->truncateNestedField($context, ['request', 'sql'], 'request.sql', $maxBytes, $truncation);
            $this->truncateNestedField($context, ['response', 'body'], 'response.body', $maxBytes, $truncation);
        }

        if ($truncation !== []) {
            $context['payload_truncation'] = $truncation;
        }
    }

    /**
     * 定位嵌套字段并执行容量限制。
     *
     * 文本超限时保留有界预览；数组或对象超限时设为 null，避免把结构化字段临时改成
     * 字符串并造成下游索引类型不稳定。原始字节数统一写入 payload_truncation。
     *
     * @param array<string, mixed> $context
     * @param string[] $segments
     * @param array<string, mixed> $truncation
     */
    private function truncateNestedField(
        array &$context,
        array $segments,
        string $path,
        int $maxBytes,
        array &$truncation,
    ): void {
        $value = &$context;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return;
            }
            $value = &$value[$segment];
        }

        if ($value === null) {
            return;
        }

        $serialized = is_string($value)
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (! is_string($serialized) || strlen($serialized) <= $maxBytes) {
            return;
        }

        $originalBytes = strlen($serialized);
        $value = is_string($value) ? $this->truncateString($serialized, $maxBytes) : null;
        $truncation[$path] = [
            'limit_bytes' => $maxBytes,
            'original_bytes' => $originalBytes,
        ];
    }

    /**
     * 结构化字段超限时设为 null，避免数组被替换成字符串后产生索引类型冲突。
     *
     * @param array<string, mixed> $context
     * @param string[] $segments
     * @param array<string, mixed> $truncation
     */
    private function omitNestedField(
        array &$context,
        array $segments,
        string $path,
        int $maxBytes,
        array &$truncation,
    ): void {
        $value = &$context;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return;
            }
            $value = &$value[$segment];
        }

        if ($value === null) {
            return;
        }

        $serialized = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if (! is_string($serialized) || strlen($serialized) <= $maxBytes) {
            return;
        }

        $truncation[$path] = [
            'limit_bytes' => $maxBytes,
            'original_bytes' => strlen($serialized),
        ];
        $value = null;
    }

    /**
     * 按字节上限生成可安全写入 JSON 日志的预览值。
     *
     * 非 UTF-8 内容先转为带 base64: 前缀的文本，防止 Formatter 因非法字节导致整条
     * 日志编码失败；截断时优先使用 mb_strcut，确保不会切断多字节字符。
     */
    private function truncateString(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 3) {
            return substr('...', 0, $maxBytes);
        }

        if (preg_match('//u', $value) !== 1) {
            $value = 'base64:' . base64_encode($value);
        }

        $limit = $maxBytes - 3;
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $limit, 'UTF-8') . '...';
        }

        $result = substr($value, 0, $limit);
        while ($result !== '' && preg_match('//u', $result) !== 1) {
            $result = substr($result, 0, -1);
        }

        return $result . '...';
    }
}

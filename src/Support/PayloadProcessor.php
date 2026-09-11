<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;

/**
 * 对 API、Guzzle 和 Redis 日志执行字段脱敏与容量限制。
 *
 * 处理顺序固定为“结构化字段脱敏 -> 请求 URL/Body 脱敏 -> Redis AUTH 兜底 ->
 * 大字段截断”，避免截断后失去结构而无法继续识别敏感字段。处理器由 LogWriter
 * 在日志子协程内调用；dblog 暂不参与处理，以保持现有 SQL 和 bindings 输出行为。
 *
 * 当前只解析 JSON 与 application/x-www-form-urlencoded 请求体；multipart/form-data
 * 及其他原始文本只应用容量限制。Redis 的格式化 command 字符串也不会被重新解析，
 * 调用方不应将这些未结构化内容视为已经完成字段级脱敏。
 */
class PayloadProcessor implements PayloadProcessorInterface
{
    public function __construct(private readonly LogConfig $config)
    {
    }

    public function process(string $type, array $context): array
    {
        // 数据库日志需要保留 SQL、bindings 和结果的既有类型，当前不参与任何内容处理。
        if ($type === 'dblog') {
            return $context;
        }

        $fields = array_fill_keys(array_map('strtolower', $this->config->payloadSensitiveFields()), true);
        $replacement = $this->config->payloadRedactionValue();

        // 先递归处理已结构化的 Header、Query 参数、响应数组及 Redis parameters。
        $context = $this->redactArray($context, $fields, $replacement);
        // URL 与字符串请求体需要按各自编码格式进行第二阶段处理。
        $this->redactRequest($context, $fields, $replacement);

        if ($type === 'redislog') {
            $this->redactRedisAuth($context, $replacement);
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
     * 处理 request.url 和已快照为字符串的 request.options。
     *
     * JSON 优先按内容识别，不强制依赖 Content-Type；表单字符串只有在明确声明为
     * application/x-www-form-urlencoded 时才解析，避免误改普通文本。
     *
     * @param array<string, mixed> $context
     * @param array<string, true> $fields
     */
    private function redactRequest(array &$context, array $fields, string $replacement): void
    {
        if ($fields === [] || ! isset($context['request']) || ! is_array($context['request'])) {
            return;
        }

        $request = &$context['request'];
        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = $this->redactUrl($request['url'], $fields, $replacement);
        }

        if (! isset($request['options']) || ! is_string($request['options']) || $request['options'] === '') {
            return;
        }

        $decoded = json_decode($request['options'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $decoded = $this->redactArray($decoded, $fields, $replacement);
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $request['options'] = $encoded;
            }

            return;
        }

        if ($this->requestContentType($request) === 'application/x-www-form-urlencoded') {
            $request['options'] = $this->redactQueryString($request['options'], $fields, $replacement);
        }

        // multipart/form-data 需要按 boundary 解析；其他非 JSON 原始文本也缺少可靠结构。
        // 当前保持原值并仅在后续执行容量限制，避免用不完整解析破坏日志内容。
    }

    /**
     * 从 Header 中提取不带 charset、boundary 等参数的媒体类型。
     *
     * @param array<string, mixed> $request
     */
    private function requestContentType(array $request): string
    {
        if (! isset($request['headers']) || ! is_array($request['headers'])) {
            return '';
        }

        foreach ($request['headers'] as $name => $value) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }

            $line = is_array($value) ? implode(',', $value) : (string) $value;

            return strtolower(trim(explode(';', $line, 2)[0]));
        }

        return '';
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
     * Redis AUTH 参数没有字段名，因此始终遮蔽，避免通用字段配置关闭后泄露凭据。
     *
     * 此处仅处理结构化 parameters；request.command 是监听器生成的展示字符串，当前
     * 不重新解析。普通 Redis 命令仍依赖 parameters 中的字段名执行通用脱敏。
     *
     * @param array<string, mixed> $context
     */
    private function redactRedisAuth(array &$context, string $replacement): void
    {
        $command = $context['request']['command'] ?? null;
        if (! is_string($command) || preg_match('/^AUTH(?:\s|$)/i', $command) !== 1) {
            return;
        }

        if (array_key_exists('parameters', $context['request'])) {
            $context['request']['parameters'] = $this->replaceAllValues(
                $context['request']['parameters'],
                $replacement,
            );
        }
    }

    /**
     * 递归替换 AUTH 的全部参数，兼容仅密码和用户名加密码两种命令形式。
     */
    private function replaceAllValues(mixed $value, string $replacement): mixed
    {
        if (! is_array($value)) {
            return $replacement;
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_array($item)
                ? $this->replaceAllValues($item, $replacement)
                : $replacement;
        }

        return $value;
    }

    /**
     * 仅限制各采集器中可能快速膨胀的负载字段，基础定位字段保持完整。
     *
     * apilog/sdklog 限制请求体和响应体；redislog 限制参数和结果。发生截断时，
     * payload_truncation 会按字段路径记录截断前的字节数。
     *
     * @param array<string, mixed> $context
     */
    private function truncatePayloads(string $type, array &$context): void
    {
        $maxBytes = $this->config->payloadMaxBytes();
        if ($maxBytes === null) {
            return;
        }

        $truncation = [];
        if (in_array($type, ['apilog', 'sdklog'], true)) {
            $this->truncateNestedField($context, ['request', 'options'], 'request.options', $maxBytes, $truncation);
            $this->truncateNestedField($context, ['response'], 'response', $maxBytes, $truncation);
        } elseif ($type === 'redislog') {
            $this->truncateNestedField($context, ['request', 'parameters'], 'request.parameters', $maxBytes, $truncation);
            $this->truncateNestedField($context, ['response'], 'response', $maxBytes, $truncation);
        }

        if ($truncation !== []) {
            $context['payload_truncation'] = $truncation;
        }
    }

    /**
     * 定位嵌套字段并在超限时替换为字符串预览。
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

        // 数组超限后会变成 JSON 预览字符串；payload_truncation 记录原始字节数，便于
        // 日志消费者区分“原本就是字符串”和“为控制容量而序列化”的字段。
        $serialized = is_string($value)
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (! is_string($serialized) || strlen($serialized) <= $maxBytes) {
            return;
        }

        $originalBytes = strlen($serialized);
        $value = $this->truncateString($serialized, $maxBytes);
        $truncation[$path] = ['original_bytes' => $originalBytes];
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

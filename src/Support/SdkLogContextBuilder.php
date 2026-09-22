<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Throwable;

/**
 * 分两阶段构建 Guzzle 日志上下文。
 *
 * request() 必须在请求交给 handler 前执行；complete() 在 Promise 完成后合并响应、异常、
 * 耗时和两阶段产生的 payload 保护信息。response_enabled=false 只省略响应 Header 与 body，
 * 真实 HTTP status_code 始终保留用于日志判级。
 */
final readonly class SdkLogContextBuilder
{
    /** 组合 SDK 响应开关与请求期 Stream 快照器。 */
    public function __construct(private LogConfig $config, private PayloadSnapshotter $snapshotter) {}

    /** 在请求交给 handler 前快照可能被消费的请求体。
     * @return array<string, mixed>
     */
    public function request(RequestInterface $request): array
    {
        $context = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
        ];
        $snapshot = $this->snapshotter->snapshot($request->getBody(), 'request.body');
        if ($snapshot->contents !== null) {
            $context['body'] = $snapshot->contents;
        }
        if ($snapshot->protection !== null) {
            // 私有暂存字段只跨越 Promise 生命周期，complete() 会迁移后删除。
            $context['_payload_protection'] = [$snapshot->protection->toArray()];
        }

        return $context;
    }

    /**
     * 合并 Promise 完成后的响应、错误、耗时及请求期保护记录。
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function complete(array $request, float $startedAt, ?ResponseInterface $response, mixed $reason = null): array
    {
        $protections = $request['_payload_protection'] ?? [];
        unset($request['_payload_protection']);
        $context = [
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'request' => $request,
        ];
        if ($response !== null) {
            $responseContext = ['status_code' => $response->getStatusCode()];
            if ($this->config->responseEnabled(Collector::Sdk)) {
                $responseContext['headers'] = $response->getHeaders();
                $snapshot = $this->snapshotter->snapshot($response->getBody(), 'response.body');
                if ($snapshot->contents !== null) {
                    $responseContext['body'] = $snapshot->contents;
                }
                if ($snapshot->protection !== null) {
                    $protections[] = $snapshot->protection->toArray();
                }
            }
            $context['response'] = $responseContext;
        }
        if ($reason instanceof Throwable) {
            $context['error'] = [
                'type' => $reason::class,
                'message' => $reason->getMessage(),
                'code' => $reason->getCode(),
            ];
        } elseif ($reason !== null) {
            $context['error'] = ['type' => get_debug_type($reason), 'message' => (string) $reason];
        }
        if ($protections !== []) {
            $context['payload_protection'] = $protections;
        }

        return $context;
    }
}

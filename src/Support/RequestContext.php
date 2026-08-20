<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Context\Context;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * 管理当前协程的请求追踪上下文。
 *
 * HTTP 中间件通过 initialize() 建立入站请求上下文；Guzzle 在没有 HTTP 请求的命令、
 * 消费者等协程中则通过 initializeRequestId() 创建可继续透传的 request-id。
 */
class RequestContext
{
    /**
     * @param LogConfig $config 公共包的请求上下文配置
     */
    public function __construct(private LogConfig $config)
    {
    }

    /**
     * 初始化 HTTP 入站请求的 request-id 与开始时间。
     *
     * 优先使用上游传入的 request-id；不存在时生成 UUID v7，同时将 request-id 写入
     * 协程 Context。若请求缺少对应 Header，返回带 Header 的不可变请求对象。
     */
    public function initialize(ServerRequestInterface $request): ServerRequestInterface
    {
        // 获取header头中的 request-id (key)
        $xRequestIdHeaderKey = $this->config->requestIdHeader();
        // getHeaderLine() 在 Header 缺失或值为空时均返回空字符串；将其视为未传入，
        // 由 initializeTrace() 生成新的 ID，避免把空值写入协程上下文和下游请求。
        $inboundRequestId = trim($request->getHeaderLine($xRequestIdHeaderKey));
        $requestId = $this->initializeTrace($inboundRequestId !== '' ? $inboundRequestId : null);

        // PSR-7 请求对象不可变。Header 缺失或为空时，用生成的 ID 覆盖并传给后续中间件。
        return $inboundRequestId !== ''
            ? $request
            : $request->withHeader($xRequestIdHeaderKey, $requestId);
    }

    /**
     * 为当前协程生成并保存 request-id。
     *
     * 已存在 request-id 时直接复用，避免同一协程内的多个 SDK 请求产生不同链路标识。
     */
    public function initializeRequestId(): string
    {
        // 协程上下文中不存在 就生成新的然后写入
        $requestId = $this->id() ?? Uuid::uuid7()->toString();
        Context::set($this->config->requestIdContextKey(), $requestId);

        return $requestId;
    }

    /**
     * 初始化当前协程或命令执行环境的完整 trace 上下文。
     *
     * HTTP 场景可传入上游 request-id；RPC、命令行和无入站请求的后台任务不传参数时
     * 会复用当前 Context 中的 request-id，或创建新的 UUID v7。
     */
    public function initializeTrace(?string $requestId = null): string
    {
        // 不存在就生成
        $requestId ??= $this->initializeRequestId();
        // 写入上下文 request-id
        Context::set($this->config->requestIdContextKey(), $requestId);
        // 写入上下文 开始时间
        Context::set($this->config->requestStartContextKey(), microtime(true));

        return $requestId;
    }

    /**
     * 获取当前协程的 request-id；未初始化时返回 null。
     */
    public function id(): ?string
    {
        $id = Context::get($this->config->requestIdContextKey());
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * 获取当前 HTTP 入站请求的开始时间；非 HTTP 协程或未初始化时返回 null。
     */
    public function startTime(): ?float
    {
        $time = Context::get($this->config->requestStartContextKey());
        return is_numeric($time) ? (float) $time : null;
    }
}

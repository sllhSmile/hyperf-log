<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Middleware;

use Hyperf\Context\ResponseContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Support\LogConfig;

/**
 * HTTP 全局 trace 中间件。
 *
 * ConfigProvider 将本中间件注册在 middlewares.http，因此它会处理 HTTP server 的
 * 全部路由。RPC 和 CLI 不会经过 PSR-15 HTTP 中间件：CLI 由 CommandTraceListener
 * 处理，RPC 需要在所使用 RPC 组件的 middleware 中调用 RequestContext::start()。
 * 入站 request-id 只做 trim 后透传，不在此处施加 UUID 等格式约束。
 */
final class LogMiddleware implements MiddlewareInterface
{
    /** 注入入口链路配置与协程上下文，不保存跨请求的 trace。 */
    public function __construct(private readonly LogConfig $config, private readonly RequestContext $requestContext) {}

    /** 初始化当前 HTTP 链路，并在正常及异常响应路径透传 request-id。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestIdHeader = $this->config->requestIdHeader();
        $inboundRequestId = trim($request->getHeaderLine($requestIdHeader));

        // HTTP 入口始终开始一条完整的新 trace，避免 Worker/协程复用时继承旧状态。
        $trace = $this->requestContext->start($inboundRequestId !== '' ? $inboundRequestId : null);
        if ($inboundRequestId === '') {
            $request = $request->withHeader($requestIdHeader, $trace->requestId);
        }

        // Hyperf 在 middleware 调用链外生成未捕获异常的响应。提前写入其可变响应上下文，
        // 确保异常路径也携带 request-id；正常返回值仍在下方按 PSR-7 方式设置 Header。
        ResponseContext::getOrNull()?->setHeader($requestIdHeader, $trace->requestId);
        $response = $handler->handle($request);

        // 无论上游是否携带 request-id，响应都回写最终生效的链路标识，便于客户端检索。
        return $response->withHeader($requestIdHeader, $trace->requestId);
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\RequestContext;

/**
 * HTTP 全局 trace 中间件。
 *
 * ConfigProvider 将本中间件注册在 middlewares.http，因此它会处理 HTTP server 的
 * 全部路由。RPC 和 CLI 不会经过 PSR-15 HTTP 中间件：CLI 由 CommandTraceListener
 * 处理，RPC 需要在所使用 RPC 组件的 middleware 中调用 RequestContext::initializeTrace()。
 */
class LogMiddleware implements MiddlewareInterface
{
    /**
     * @param LogConfig $config 用于判断采集器开关的配置读取器
     * @param RequestContext $requestContext 用于创建并保存请求链路数据的上下文服务
     */
    public function __construct(private LogConfig $config, private RequestContext $requestContext)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 初始化与采集器开关无关，保证普通业务日志、响应和采集器共用同一个 trace。
        $request = $this->requestContext->initialize($request);
        $response = $handler->handle($request);

        // 无论上游是否携带 request-id，响应都回写最终生效的链路标识，便于客户端检索。
        return $response->withHeader($this->config->requestIdHeader(), $this->requestContext->id());
    }
}

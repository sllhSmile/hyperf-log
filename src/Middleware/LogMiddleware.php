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
        // 第 1 步：所有采集器关闭时不创建 UUID、不写入 Context，保持框架原有请求路径。
        if (! $this->config->anyEnabled()) {
            return $handler->handle($request);
        }

        // 第 2 步：初始化 request-id 和开始时间；缺失的 request-id 会回写到不可变请求对象。
        $request = $this->requestContext->initialize($request);

        // 第 3 步：将携带 trace Header 的请求传递给后续全局、路由和业务中间件。
        return $handler->handle($request);
    }
}

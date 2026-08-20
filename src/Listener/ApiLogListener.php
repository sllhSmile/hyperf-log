<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\HttpServer\Event\RequestHandled;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

class ApiLogListener implements ListenerInterface
{
    /**
     * 注入 API 日志所需配置、写入器和请求上下文。
     */
    public function __construct(
        private readonly LogConfig      $config,
        private readonly LogWriter      $writer,
        private readonly RequestContext $requestContext,
    ) {
    }

    public function listen(): array
    {
        // RequestHandled 在 HTTP 请求生命周期结束时触发，包含请求、响应与异常信息。
        return [RequestHandled::class];
    }

    /**
     * 写入单条 API 请求日志。
     *
     * @param object $event Hyperf 事件调度器传入的事件对象
     */
    public function process(object $event): void
    {
        // 仅处理目标事件，并由 apilog channel 的 enabled 开关控制采集。
        if (! $event instanceof RequestHandled || ! $this->config->enabled('apilog')) {
            return;
        }

        // 请求结束时计算耗时，并从协程上下文获得中间件写入的开始时间。
        $endTime = microtime(true);
        $startTime = $this->requestContext->startTime();
        $request = $event->request;
        $response = $event->response;

        $this->writer->info('apilog', [
            // request_id 关联同一 HTTP 请求内的 API、数据库、Redis 和 SDK 日志。
            'request_id' => $this->requestContext->id(),
            'server' => $event->server,
            // 与宿主项目日志结构保持一致，便于下游日志平台按应用检索。
            'app_name' => $this->config->appName(),
            'request' => $request ? [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'options' => (string) $request->getBody(),
            ] : null,
            // 与原 apilog 一致：JSON 响应转数组，非 JSON 响应保留原字符串。
            'response' => $response ? $this->responseBody($response) : null,
            'exception' => $event->exception ? [
                'class' => $event->exception::class,
                'message' => $event->exception->getMessage(),
                'code' => $event->exception->getCode(),
            ] : null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            // 保持宿主项目 run_time 的毫秒字符串格式。
            'run_time' => $startTime === null ? null : round(($endTime - $startTime) * 1000, 2) . 'ms',
        ]);
    }

    /**
     * 获取响应体并优先转换 JSON 内容。
     *
     * @param \Psr\Http\Message\ResponseInterface $response HTTP 响应对象
     */
    private function responseBody(\Psr\Http\Message\ResponseInterface $response): mixed
    {
        $body = (string) $response->getBody();

        return json_decode($body, true) ?? $body;
    }
}

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
        // PSR-7 响应体是一个流对象。直接转换为字符串会从当前指针位置读到 EOF，
        // 而 RequestHandled 事件发生在响应真正发送之前；如果不恢复指针，
        // 后续 ResponseEmitter 可能只能读到空内容，客户端就会收到空响应。
        $stream = $response->getBody();

        // 只有可回绕（seekable）的流才能安全地先读日志、再恢复读取位置。
        // 不可回绕的流仍按原行为读取，但无法改变其底层流的当前位置。
        $position = null;
        if ($stream->isSeekable()) {
            // 保存调用本方法前的位置，避免破坏调用方已经建立的读取状态。
            $position = $stream->tell();

            // 日志必须从响应体开头读取，否则如果指针已经移动过，日志会缺少前半段内容。
            $stream->rewind();
        }

        try {
            // 读取完整响应体，供日志记录以及后面的 JSON 解析使用。
            $body = (string) $stream;
        } finally {
            // 无论读取或字符串转换是否抛出异常，都要尝试恢复流位置。
            // 这样日志监听器发生问题时，也不会额外破坏正常的 HTTP 响应发送流程。
            if ($stream->isSeekable()) {
                // 恢复到读取日志前的位置；通常该位置是 0，ResponseEmitter 随后可正常发送正文。
                $stream->seek($position ?? 0);
            }
        }

        // JSON 响应转换为数组，便于日志检索；非 JSON 响应保留原始字符串。
        return json_decode($body, true) ?? $body;
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\HttpServer\Event\RequestHandled;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;
use Sllhsmile\HyperfLog\Support\StreamSnapshotter;

class ApiLogListener implements ListenerInterface
{
    private readonly StreamSnapshotter $streamSnapshotter;

    /**
     * 注入 API 日志所需配置、写入器和请求上下文。
     *
     * @param StreamSnapshotter|null $streamSnapshotter 不消费业务流的请求/响应 Body 快照器
     */
    public function __construct(
        private readonly LogConfig      $config,
        private readonly LogWriter      $writer,
        private readonly RequestContext $requestContext,
        ?StreamSnapshotter $streamSnapshotter = null,
    ) {
        // 保留原有三参数构造兼容；容器和测试均可显式注入统一的 Stream 快照策略。
        $this->streamSnapshotter = $streamSnapshotter ?? new StreamSnapshotter();
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
        $truncation = [];

        $context = [
            // request_id 由 CustomizeJsonFormatter 从 RequestContext 统一写入顶层。
            'server' => $event->server,
            // 与宿主项目日志结构保持一致，便于下游日志平台按应用检索。
            'app_name' => $this->config->appName(),
            'request' => $request ? $this->requestLog($request, $truncation) : null,
            'response' => $response ? $this->responseLog($response, $truncation) : null,
            'exception' => $event->exception ? [
                'class' => $event->exception::class,
                'message' => $event->exception->getMessage(),
                'code' => $event->exception->getCode(),
            ] : null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_ms' => $startTime === null ? null : round(($endTime - $startTime) * 1000, 2),
        ];

        if ($truncation !== []) {
            $context['payload_truncation'] = $truncation;
        }

        $this->writer->info('apilog', $context);
    }

    /**
     * 在当前请求协程中生成可安全交给日志子协程的数据快照。
     *
     * multipart/form-data 已由 HTTP Server 拆分为普通字段和上传文件。此处直接读取
     * 解析结果，文件只保留客户端元数据，既能让 PayloadProcessor 按字段脱敏，也避免
     * 原始 multipart Body 中的密码和文件内容进入日志。
     *
     * @param array<string, array<string, int>> $truncation
     * @return array<string, mixed>
     */
    private function requestLog(ServerRequestInterface $request, array &$truncation): array
    {
        $context = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
        ];

        if ($this->isMultipart($request)) {
            $parsedBody = $request->getParsedBody();
            $context['body'] = is_array($parsedBody)
                ? $parsedBody
                : (is_object($parsedBody) ? get_object_vars($parsedBody) : []);
            $context['files'] = $this->uploadedFileMetadata($request->getUploadedFiles());

            return $context;
        }

        $context['body'] = $this->snapshotBody($request->getBody(), 'request.body', $truncation);

        return $context;
    }

    /**
     * 只按媒体类型判断 multipart，忽略 boundary 等参数并兼容 Header 大小写。
     */
    private function isMultipart(ServerRequestInterface $request): bool
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

        return $contentType === 'multipart/form-data';
    }

    /**
     * 将上传文件树转换为纯数组，禁止把 UploadedFile 或其 Stream 传入日志子协程。
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function uploadedFileMetadata(array $files): array
    {
        $metadata = [];
        foreach ($files as $name => $file) {
            if (is_array($file)) {
                $metadata[$name] = $this->uploadedFileMetadata($file);
                continue;
            }

            if (! $file instanceof UploadedFileInterface) {
                continue;
            }

            $metadata[$name] = [
                'filename' => $file->getClientFilename(),
                'media_type' => $file->getClientMediaType(),
                'size' => $file->getSize(),
                'error' => $file->getError(),
            ];
        }

        return $metadata;
    }

    /**
     * 生成 HTTP 响应元数据和有界 Body 快照。
     *
     * @param array<string, array<string, int>> $truncation
     * @return array{status_code: int, headers: array<string, string[]>, body: string|null}
     */
    private function responseLog(ResponseInterface $response, array &$truncation): array
    {
        // RequestHandled 发生在 ResponseEmitter 发送正文之前。不可回绕的响应体不能为了
        // 日志而读取，否则客户端可能收到空内容；这种场景固定返回 null。
        $body = $this->snapshotBody($response->getBody(), 'response.body', $truncation);

        return [
            'status_code' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => $body,
        ];
    }

    /**
     * 在业务协程内执行有界 Stream 快照，并把超限信息交给日志子协程继续处理。
     *
     * @param array<string, array<string, int>> $truncation
     */
    private function snapshotBody(StreamInterface $stream, string $path, array &$truncation): ?string
    {
        $maxBytes = $this->config->payloadMaxBytes();
        $snapshot = $this->streamSnapshotter->snapshot($stream, $maxBytes);
        if ($snapshot->truncated && $maxBytes !== null) {
            $truncation[$path] = ['limit_bytes' => $maxBytes];
            if ($snapshot->originalBytes !== null) {
                $truncation[$path]['original_bytes'] = $snapshot->originalBytes;
            }
        }

        return $snapshot->contents;
    }
}

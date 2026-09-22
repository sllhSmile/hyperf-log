<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\HttpServer\Event\RequestHandled;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\HttpLogLevel;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;

/**
 * 在 Hyperf 完成 HTTP 请求后采集 server 日志。
 *
 * PSR-7 body 必须在当前请求协程内完成安全快照；CollectorLogger 随后在提交前执行内容
 * 保护，并可将 Handler IO 交给异步队列。response_enabled=false 时不会读取响应流。
 * 流读取和 Handler 写入失败会分别降级为 payload_protection 与内部错误日志，不改变
 * 业务响应。
 *
 * RequestHandled::exception 是 Hyperf Server 捕获的原始 Throwable；即使异常处理器已经
 * 生成响应，该字段仍然存在并优先判为 ERROR。状态码只读取 PSR-7 Response 的真实 HTTP
 * status，不读取响应 body 中的业务码。
 */
final readonly class ApiLogListener implements ListenerInterface
{
    public function __construct(
        private LogConfig $config,
        private CollectorLoggerInterface $logger,
        private RequestContext $requestContext,
        private PayloadSnapshotter $snapshotter,
    ) {}

    /** @return class-string[] */
    public function listen(): array
    {
        return [RequestHandled::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof RequestHandled || ! $this->config->enabled(Collector::Api)) {
            return;
        }

        $context = [];
        $protections = [];
        if ($event->request instanceof ServerRequestInterface) {
            $context['request'] = $this->request($event->request, $protections);
        }
        if ($event->response instanceof ResponseInterface) {
            $context['response'] = $this->response($event->response, $protections);
        }
        if ($event->exception !== null) {
            $context['error'] = [
                'type' => $event->exception::class,
                'message' => $event->exception->getMessage(),
                'code' => $event->exception->getCode(),
            ];
        }
        $startedAt = $this->requestContext->startTime();
        if ($startedAt !== null) {
            $context['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
        }
        if ($protections !== []) {
            $context['payload_protection'] = $protections;
        }

        $this->logger->log(
            HttpLogLevel::resolve($context['response']['status_code'] ?? null, isset($context['error'])),
            Collector::Api,
            $context,
        );
    }

    /**
     * @param array<int, array<string, int|string>> $protections
     * @param-out array<int, array<string, int|string>> $protections
     * @return array<string, mixed>
     */
    private function request(ServerRequestInterface $request, array &$protections): array
    {
        $result = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
        ];
        if ($this->isMultipart($request)) {
            // multipart 流可能包含大文件；只记录解析字段和文件元数据，不读取上传内容。
            $body = $request->getParsedBody();
            $result['body'] = is_array($body) ? $body : (is_object($body) ? get_object_vars($body) : []);
            $files = $this->uploadedFiles($request->getUploadedFiles());
            if ($files !== []) {
                $result['files'] = $files;
            }

            return $result;
        }

        $this->addSnapshot($result, $request->getBody(), 'request.body', $protections);

        return $result;
    }

    /**
     * @param array<int, array<string, int|string>> $protections
     * @param-out array<int, array<string, int|string>> $protections
     * @return array<string, mixed>
     */
    private function response(ResponseInterface $response, array &$protections): array
    {
        $result = ['status_code' => $response->getStatusCode()];
        if (! $this->config->responseEnabled(Collector::Api)) {
            return $result;
        }
        $result['headers'] = $response->getHeaders();
        $this->addSnapshot($result, $response->getBody(), 'response.body', $protections);

        return $result;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<int, array<string, int|string>> $protections
     * @param-out array<int, array<string, int|string>> $protections
     */
    private function addSnapshot(array &$message, StreamInterface $stream, string $path, array &$protections): void
    {
        $snapshot = $this->snapshotter->snapshot($stream, $path);
        if ($snapshot->contents !== null) {
            $message['body'] = $snapshot->contents;
        }
        if ($snapshot->protection !== null) {
            $protections[] = $snapshot->protection->toArray();
        }
    }

    private function isMultipart(ServerRequestInterface $request): bool
    {
        return strtolower(trim(explode(';', $request->getHeaderLine('content-type'), 2)[0])) === 'multipart/form-data';
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function uploadedFiles(array $files): array
    {
        $result = [];
        foreach ($files as $name => $file) {
            if (is_array($file)) {
                $result[$name] = $this->uploadedFiles($file);
            } elseif ($file instanceof UploadedFileInterface) {
                $result[$name] = [
                    'filename' => $file->getClientFilename(),
                    'media_type' => $file->getClientMediaType(),
                    'size' => $file->getSize(),
                    'error' => $file->getError(),
                ];
            }
        }

        return $result;
    }
}

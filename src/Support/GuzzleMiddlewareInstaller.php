<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Throwable;

/**
 * 为 Guzzle HandlerStack 安装 request-id 透传和 SDK 日志采集。
 *
 * 本类不设置 timeout、connect_timeout 或 swoole 选项，调用方配置会原样传给下一层
 * handler。同步异常与 Promise rejection 都会记录后保持原始失败语义。
 */
final readonly class GuzzleMiddlewareInstaller
{
    public function __construct(
        private LogConfig $config,
        private RequestContext $requestContext,
        private SdkLogContextBuilder $contextBuilder,
        private CollectorLoggerInterface $logger,
    ) {}

    public function install(HandlerStack $stack): void
    {
        // Aspect 可能多次遇到同一 stack；固定名称保证安装操作幂等。
        $stack->remove('hyperf_log_request');
        $stack->push($this->requestMiddleware(), 'hyperf_log_request');
    }

    private function requestMiddleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                $requestId = $this->requestContext->id();
                if ($requestId !== null) {
                    $request = $request->withHeader($this->config->requestIdHeader(), $requestId);
                }
                if (! $this->config->enabled(Collector::Sdk)) {
                    return Create::promiseFor($handler($request, $options));
                }

                $startedAt = microtime(true);
                try {
                    // 在 handler 消费请求流之前完成快照；失败时仍继续真实网络请求。
                    $requestSnapshot = $this->contextBuilder->request($request);
                } catch (Throwable $exception) {
                    $this->reportFailure($exception, $requestId);

                    return Create::promiseFor($handler($request, $options));
                }
                try {
                    $promise = Create::promiseFor($handler($request, $options));
                } catch (Throwable $reason) {
                    $this->writeSafely($requestSnapshot, $startedAt, null, $reason, $requestId);
                    throw $reason;
                }

                return $promise->then(
                    function (mixed $response) use ($requestSnapshot, $startedAt, $requestId): mixed {
                        if ($response instanceof ResponseInterface) {
                            $this->writeSafely($requestSnapshot, $startedAt, $response, null, $requestId);
                        }

                        return $response;
                    },
                    function (mixed $reason) use ($requestSnapshot, $startedAt, $requestId): PromiseInterface {
                        $this->writeSafely($requestSnapshot, $startedAt, null, $reason, $requestId);

                        // 返回 rejection 而不是抛出新异常，保留 Guzzle Promise 的失败链。
                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }

    /** @param array<string, mixed> $request */
    private function writeSafely(
        array $request,
        float $startedAt,
        ?ResponseInterface $response,
        mixed $reason,
        ?string $requestId,
    ): void {
        try {
            $this->logger->info(Collector::Sdk, $this->contextBuilder->complete($request, $startedAt, $response, $reason));
        } catch (Throwable $exception) {
            $this->reportFailure($exception, $requestId);
        }
    }

    private function reportFailure(Throwable $exception, ?string $requestId): void
    {
        error_log(sprintf(
            'hyperf-log sdk write failed: %s request_id=%s',
            $exception::class,
            $requestId ?? 'unavailable',
        ));
    }
}

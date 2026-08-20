<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Aspect;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;
use Throwable;

/**
 * Guzzle HTTP 客户端的 AOP 切面类。
 *
 * 功能：在 Guzzle Client 初始化后自动注入中间件，为所有出站请求提供：
 * 1. 可配置的默认请求和连接超时时间；
 * 2. request-id、请求开始时间等统一 Header；
 * 3. sdklog 启用时的成功与异常请求结构化日志。
 *
 * 切面不依赖宿主 App 命名空间、Helper 或常量，因此可以作为独立 Composer 公共包使用。
 *
 * @see https://hyperf.wiki/3.1/#/zh-cn/aop
 */
class GuzzleLogAspect extends AbstractAspect
{
    /**
     * 需要切面的类和方法。
     *
     * 这里指定 GuzzleHttp\Client::__construct；每个 Client 创建完成后都能拿到其
     * HandlerStack，并向该实例注入本公共包的请求中间件。
     *
     * @var string[]
     */
    public array $classes = [
        Client::class . '::__construct',
    ];

    /**
     * 构造函数。
     *
     * @param LogConfig $config 读取 trace_log 与宿主 logger 配置
     * @param LogWriter $writer 使用 Hyperf LoggerFactory 写入 SDK 日志
     * @param RequestContext $requestContext 管理当前协程的 request-id 与请求开始时间
     */
    public function __construct(
        private readonly LogConfig $config,
        private readonly LogWriter $writer,
        private readonly RequestContext $requestContext,
    ) {
    }

    /**
     * AOP 切面处理方法。
     *
     * 执行流程：
     * 1. 先执行原始 Client 构造函数；
     * 2. 获得该 Client 的 HandlerStack；
     * 3. 始终注入默认超时和公共 Header 中间件；
     * 4. sdklog 启用时，再注入响应和异常日志中间件。
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        // 先完成原始 Client 初始化，避免影响 Guzzle 构造过程。
        $result = $proceedingJoinPoint->process();

        // HandlerStack 是 Guzzle 管理中间件执行顺序的容器。
        $stack = $proceedingJoinPoint->getInstance()->getConfig('handler');
        if ($stack === null) {
            return $result;
        }

        // 无论 sdklog 是否开启，公共包安装后均提供超时和追踪 Header 能力。
        $this->pushTimeoutMiddleware($stack);
        $this->pushRequestHeaderMiddleware($stack);

        // SDK 日志是可选能力，只有对应 logger channel 明确启用时才记录。
        if ($this->config->enabled('sdklog')) {
            $stack->push($this->logMiddleware(), 'trace_log_sdk');
        }

        return $result;
    }

    /**
     * 注入默认超时中间件。
     *
     * 调用方显式传入 timeout 或 connect_timeout 时优先使用调用方值；缺失时才使用
     * trace_log.guzzle 中配置的默认值。
     *
     * @param mixed $stack Guzzle HandlerStack 实例
     */
    private function pushTimeoutMiddleware(mixed $stack): void
    {
        $middleware = function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                // 保留调用方设置的超时，避免公共包覆盖业务请求的个性化参数。
                $options['timeout'] = $options['timeout'] ?? $this->config->guzzleTimeout();
                // 未设置连接超时时使用 trace_log 的统一默认值。
                $options['connect_timeout'] = $options['connect_timeout'] ?? $this->config->guzzleConnectTimeout();

                return $handler($request, $options);
            };
        };

        $stack->push($middleware, 'trace_log_timeout');
    }

    /**
     * 注入出站请求 Header 中间件。
     *
     * 每次请求发送前补充 request-id 与微秒级开始时间。调用方已有的 request-id
     * 不会被覆盖，便于跨服务链路传递上游追踪标识。
     *
     * @param mixed $stack Guzzle HandlerStack 实例
     */
    private function pushRequestHeaderMiddleware(mixed $stack): void
    {
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            // 请求开始时间总是由当前出站调用生成，用于精确计算 SDK 调用耗时。
            $request = $request->withHeader($this->config->requestStartHeader(), (string) microtime(true));

            $requestIdHeader = $this->config->requestIdHeader();
            if (! $request->hasHeader($requestIdHeader)) {
                // 非 HTTP 协程中 id() 可能为空，此时生成 UUID 并保存到当前协程上下文。
                $requestId = $this->requestContext->id() ?? $this->requestContext->initializeRequestId();
                $request = $request->withHeader($requestIdHeader, $requestId);
            }

            return $request;
        }), 'trace_log_request_headers');
    }

    /**
     * 创建 SDK 请求日志中间件。
     *
     * Middleware::tap 在请求发出后拿到 Promise；成功和失败回调均记录日志，且失败
     * 回调会将原始异常继续抛出，保证不改变 Guzzle 原有的异常语义。
     */
    private function logMiddleware(): callable
    {
        return Middleware::tap(
            // 请求发送前无需额外处理，开始时间已由 Header 中间件写入。
            static function (RequestInterface $request): void {
            },
            function (RequestInterface $request, array $options, PromiseInterface $promise): void {
                // 优先使用请求 Header 时间，确保日志耗时与实际出站请求一致。
                $startTime = (float) $request->getHeaderLine($this->config->requestStartHeader());
                $startTime = $startTime > 0 ? $startTime : microtime(true);
                $requestId = $request->getHeaderLine($this->config->requestIdHeader()) ?: $this->requestContext->id();

                $promise->then(
                    function (ResponseInterface $response) use ($request, $startTime, $requestId): ResponseInterface {
                        $this->writeLog($request, $startTime, $requestId, $response);

                        return $response;
                    },
                    function (mixed $reason) use ($request, $startTime, $requestId): never {
                        $this->writeLog($request, $startTime, $requestId, null, $reason);

                        throw $reason instanceof Throwable ? $reason : new \RuntimeException((string) $reason);
                    },
                );
            },
        );
    }

    /**
     * 组织并写入 SDK 请求日志。
     *
     * 响应体转换为字符串后立即 rewind，确保记录日志不会导致业务后续读取到空响应体。
     *
     * @param RequestInterface $request 当前出站请求对象
     * @param float $startTime 请求开始时间戳
     * @param string|null $requestId 本次请求的链路标识
     * @param ResponseInterface|null $response 成功响应；异常时为 null
     * @param mixed $reason Guzzle 失败原因或异常对象
     */
    private function writeLog(
        RequestInterface $request,
        float $startTime,
        ?string $requestId,
        ?ResponseInterface $response,
        mixed $reason = null,
    ): void {
        // 第 1 步：记录日志时的时间即为请求结束时间。
        $endTime = microtime(true);
        $responseBody = null;

        // 第 2 步：响应体可能很大；未开启 sdklog.response_enabled 时完全不读取流。
        if ($response !== null && $this->config->responseEnabled('sdklog')) {
            // 第 3 步：将流读取为字符串会移动指针，读取后必须复位供业务继续消费。
            $responseBody = (string) $response->getBody();
            $response->getBody()->rewind();
        }

        // 第 4 步：请求、异常和耗时始终记录；响应字段严格受 response_enabled 控制。
        $this->writer->info('sdklog', [
            'request_id' => $requestId,
            'app_name' => $this->config->appName(),
            'request' => [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'options' => (string) $request->getBody(),
            ],
            // 与宿主 sdklog 保持兼容：开启时记录第三方返回内容，关闭时固定为 null。
            'response' => $responseBody === null ? null : json_decode($responseBody, true) ?? $responseBody,
            'exception' => $reason instanceof Throwable ? [
                'class' => $reason::class,
                'message' => $reason->getMessage(),
                'code' => $reason->getCode(),
            ] : ($reason === null ? null : ['message' => (string) $reason]),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'run_time' => round(($endTime - $startTime) * 1000, 2) . 'ms',
        ]);
    }
}

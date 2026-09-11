<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Logger\LoggerFactory;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Throwable;

/**
 * 将结构化日志的内容处理和写入移出请求主协程。
 *
 * Listener/Aspect 必须先在父协程读取 PSR-7 Stream，并将字符串或数组快照传入 info()；
 * 请求对象和 Stream 不应跨协程传递。脱敏、容量限制、格式化及日志 IO 均在 write()
 * 所在的日志子协程执行，CLI 场景则同步执行以保证进程退出前完成写入。
 */
class LogWriter
{
    private PayloadProcessorInterface $payloadProcessor;

    /**
     * @param LoggerFactory $factory Hyperf 日志记录器工厂
     * @param LogConfig $config 公共包配置读取器
     * @param RequestContext $requestContext 当前协程的链路上下文
     * @param PayloadProcessorInterface|null $payloadProcessor 日志子协程内执行的内容处理器
     */
    public function __construct(
        private LoggerFactory $factory,
        private LogConfig $config,
        private RequestContext $requestContext,
        ?PayloadProcessorInterface $payloadProcessor = null,
    ) {
        // 保留原有三参数构造方式；容器场景由 LogWriterFactory 注入可替换的接口实现。
        $this->payloadProcessor = $payloadProcessor ?? new PayloadProcessor($config);
    }

    /**
     * 使用指定采集器的 logger channel 写入 INFO 级别结构化日志。
     *
     * 该方法接收的 context 必须是可跨协程使用的数据快照，不能包含仍由父协程持有的
     * PSR-7 Stream、Request/Response 或其他请求期可变资源。
     *
     * @param string $type 采集器名称，例如 apilog 或 redislog
     * @param array<string, mixed> $context 已完成请求/响应读取的日志上下文快照
     */
    public function info(string $type, array $context): void
    {
        // 在 HTTP/RPC 协程中将日志写入移到子协程，避免文件 IO 影响当前业务协程。
        if (Coroutine::inCoroutine()) {
            // 子协程默认不继承 Context；先确保 ID 存在，再显式复制 trace 键，使
            // Formatter 与 error_log fallback 都能写入父请求的同一 request-id。
            $this->requestContext->id();
            Coroutine::fork(function () use ($type, $context): void {
                try {
                    $this->write($type, $context);
                } catch (Throwable $exception) {
                    // 日志写入失败不能影响当前请求；仅输出异常类型作为诊断兜底，
                    // 不复制上下文，避免把 Authorization 或请求 body 写入 stderr。
                    $this->reportFailure($type, $exception);
                }
            }, [$this->config->requestIdContextKey()]);

            return;
        }

        // CLI 通常不运行在协程中，采用同步兜底以保证命令结束前日志已经落盘。
        try {
            $this->write($type, $context);
        } catch (Throwable $exception) {
            // 日志系统故障不能中断命令业务；同步路径同样保持“记录失败不影响主流程”。
            $this->reportFailure($type, $exception);
        }
    }

    /**
     * 执行内容保护、Formatter 处理和 LoggerFactory 写入。
     *
     * @param string $type 采集器名称，例如 apilog 或 redislog
     * @param array<string, mixed> $context 已在父执行环境中构造完成的日志上下文
     */
    private function write(string $type, array $context): void
    {
        // HTTP/RPC 场景下本方法运行在日志子协程中，脱敏、截断、格式化和 IO 均移出
        // 当前业务协程；CLI 没有协程调度器，只能沿用同步写入行为。
        $context = $this->payloadProcessor->process($type, $context);

        // 允许宿主应用用 channel 字段将采集器映射到自定义 logger channel。
        $channel = $this->config->channel($type);
        // Logger 名称与 channel 均采用采集器名称，便于 formatter 和日志平台过滤。
        $this->factory->get($channel, $channel)->info($type, $context);
    }

    /**
     * 输出不含业务负载的最小失败诊断，避免日志故障再次静默。
     *
     * error_log() 不经过 PayloadProcessor 和 Formatter，只能写入异常类型和当前
     * request-id；禁止在这里拼接 context 或异常消息，以免二次泄露敏感数据。
     */
    private function reportFailure(string $type, Throwable $exception): void
    {
        error_log(sprintf(
            'hyperf-log %s write failed: %s request_id=%s',
            $type,
            $exception::class,
            $this->requestContext->id(),
        ));
    }
}

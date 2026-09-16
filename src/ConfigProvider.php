<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog;

use Sllhsmile\HyperfLog\Aspect\GuzzleLogAspect;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Listener\ApiLogListener;
use Sllhsmile\HyperfLog\Listener\CommandTraceListener;
use Sllhsmile\HyperfLog\Listener\DatabaseLogListener;
use Sllhsmile\HyperfLog\Listener\RedisLogListener;
use Sllhsmile\HyperfLog\Middleware\LogMiddleware;
use Sllhsmile\HyperfLog\Support\CollectorLogger;
use Sllhsmile\HyperfLog\Support\PayloadProcessor;

final class ConfigProvider
{
    /**
     * 注册公共包所需的监听器、切面、中间件及可发布配置。
     *
     * 采集器策略与输出 channel 选择统一由 trace_log 控制；logger 配置只负责定义
     * 实际的 Handler 与 Formatter。
     *
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                CollectorLoggerInterface::class => CollectorLogger::class,
                PayloadProcessorInterface::class => PayloadProcessor::class,
            ],
            'listeners' => [
                ApiLogListener::class,
                CommandTraceListener::class,
                DatabaseLogListener::class,
                RedisLogListener::class,
            ],
            'aspects' => [
                GuzzleLogAspect::class,
            ],
            'middlewares' => [
                'http' => [
                    LogMiddleware::class,
                ],
            ],
            'publish' => [
                [
                    'id' => 'trace-log-config',
                    'description' => 'Request tracing and structured log configuration for the Hyperf log package.',
                    'source' => __DIR__ . '/../publish/trace_log.php',
                    'destination' => \BASE_PATH . '/config/autoload/trace_log.php',
                ],
            ],
        ];
    }
}

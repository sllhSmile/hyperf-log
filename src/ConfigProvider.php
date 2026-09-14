<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog;

use Sllhsmile\HyperfLog\Aspect\GuzzleLogAspect;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Factory\LogWriterFactory;
use Sllhsmile\HyperfLog\Listener\ApiLogListener;
use Sllhsmile\HyperfLog\Listener\CommandTraceListener;
use Sllhsmile\HyperfLog\Listener\DatabaseLogListener;
use Sllhsmile\HyperfLog\Listener\RedisLogListener;
use Sllhsmile\HyperfLog\Middleware\LogMiddleware;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\PayloadProcessor;

class ConfigProvider
{
    /**
     * 注册公共包所需的监听器、切面、中间件及可发布配置。
     *
     * 采集器是否真正记录日志由各 logger channel 的 enabled 字段控制，注册本身
     * 不会改变宿主项目已有的日志 Listener、Middleware 或 Aspect。
     *
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                PayloadProcessorInterface::class => PayloadProcessor::class,
                LogWriter::class => LogWriterFactory::class,
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
                    LogMiddleware::class => PHP_INT_MAX,
                ],
            ],
            'publish' => [
                [
                    'id' => 'trace-log-config',
                    'description' => 'Request tracing and Guzzle configuration for the Hyperf log package.',
                    'source' => __DIR__ . '/../publish/trace_log.php',
                    'destination' => \BASE_PATH . '/config/autoload/trace_log.php',
                ],
            ],
        ];
    }
}

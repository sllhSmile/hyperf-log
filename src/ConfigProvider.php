<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog;

use Sllhsmile\HyperfLog\Aspect\GuzzleLogAspect;
use Sllhsmile\HyperfLog\Listener\ApiLogListener;
use Sllhsmile\HyperfLog\Listener\CommandTraceListener;
use Sllhsmile\HyperfLog\Listener\DatabaseLogListener;
use Sllhsmile\HyperfLog\Listener\RedisLogListener;
use Sllhsmile\HyperfLog\Middleware\LogMiddleware;

class ConfigProvider
{
    /**
     * 注册公共包所需的监听器、切面、中间件及可发布配置。
     *
     * 采集器是否真正记录日志由各 logger channel 的 enabled 字段控制，注册本身
     * 不会改变宿主项目已有的日志 Listener、Middleware 或 Aspect。
     */
    public function __invoke(): array
    {
        return [
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
                    LogMiddleware::class => 99999,
                ],
            ],
            'publish' => [
                [
                    'id' => 'trace-log-config',
                    'description' => 'Request tracing and Guzzle configuration for the Hyperf log package.',
                    'source' => __DIR__ . '/../resources/trace_log.php',
                    'destination' => \BASE_PATH . '/config/autoload/trace_log.php',
                ],
            ],
        ];
    }
}

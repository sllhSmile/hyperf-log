<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Monolog\Level;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;

/**
 * 将 Monolog Level 映射到现有的八级 CollectorLoggerInterface。
 *
 * 保持接口不新增通用 log() 方法，避免要求宿主的自定义实现同步修改公共契约。
 */
final class LogLevelDispatcher
{
    /** @param array<string, mixed> $context */
    public static function write(
        CollectorLoggerInterface $logger,
        Level $level,
        Collector $collector,
        array $context,
        ?LogOrigin $origin = null,
    ): void {
        $method = match ($level) {
            Level::Emergency => 'emergency',
            Level::Alert => 'alert',
            Level::Critical => 'critical',
            Level::Error => 'error',
            Level::Warning => 'warning',
            Level::Notice => 'notice',
            Level::Info => 'info',
            Level::Debug => 'debug',
        };

        if ($origin === null) {
            $logger->{$method}($collector, $context);
            return;
        }

        $logger->{$method}($collector, $context, $origin);
    }

    private function __construct() {}
}

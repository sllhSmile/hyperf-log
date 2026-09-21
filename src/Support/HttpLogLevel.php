<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Monolog\Level;

/**
 * 统一 API 与 SDK 的 HTTP 日志判级策略。
 *
 * 存在 error 上下文时优先记为 ERROR；否则真实 HTTP 4xx 为 WARNING、5xx 为 ERROR，
 * 其余为 INFO。
 * 缺失、非整数或不在 100–599 的状态码按 INFO 处理，响应 body 中的业务码不参与判级。
 */
final class HttpLogLevel
{
    public static function resolve(mixed $statusCode, bool $hasError): Level
    {
        if ($hasError) {
            return Level::Error;
        }

        $statusClass = is_int($statusCode) ? HttpStatusClass::tryFromStatusCode($statusCode) : null;

        return match ($statusClass) {
            HttpStatusClass::ServerError => Level::Error,
            HttpStatusClass::ClientError => Level::Warning,
            default => Level::Info,
        };
    }

    private function __construct() {}
}

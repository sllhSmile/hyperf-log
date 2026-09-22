<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Throwable;

/**
 * 将采集链路自身的异常压缩为可安全写入 PHP error_log 的单行诊断。
 *
 * @internal
 */
final class InternalDiagnostic
{
    public const MAX_BYTES = 4096;

    /** 将格式化后的单行异常诊断直接写入 PHP error_log。 */
    public static function reportException(string $prefix, Throwable $exception, ?string $requestId): void
    {
        error_log(self::formatException($prefix, $exception, $requestId));
    }

    /** 转义异常消息中的换行并将完整诊断限制在固定字节数。 */
    public static function formatException(string $prefix, Throwable $exception, ?string $requestId): string
    {
        $message = str_replace(["\r", "\n"], ['\\r', '\\n'], $exception->getMessage());
        $diagnostic = sprintf(
            '%s exception=%s file=%s line=%d request_id=%s message=%s',
            $prefix,
            $exception::class,
            basename($exception->getFile()),
            $exception->getLine(),
            $requestId ?? 'unavailable',
            $message,
        );

        if (strlen($diagnostic) <= self::MAX_BYTES) {
            return $diagnostic;
        }

        return substr($diagnostic, 0, self::MAX_BYTES - 3) . '...';
    }
}

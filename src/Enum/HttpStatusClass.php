<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Enum;

/** 标准 HTTP 状态码的五个百位类别。 */
enum HttpStatusClass: int
{
    case Informational = 1;
    case Success = 2;
    case Redirection = 3;
    case ClientError = 4;
    case ServerError = 5;

    /** 仅接受 100–599；范围外的值返回 null。 */
    public static function tryFromStatusCode(int $statusCode): ?self
    {
        if ($statusCode < 100 || $statusCode > 599) {
            return null;
        }

        return self::tryFrom(intdiv($statusCode, 100));
    }
}

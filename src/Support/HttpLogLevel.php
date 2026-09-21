<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Enum\Collector;

/** Applies the shared API/SDK status and exception level policy. */
final class HttpLogLevel
{
    /** @param array<string, mixed> $context */
    public static function write(
        CollectorLoggerInterface $logger,
        Collector $collector,
        array $context,
        ?LogOrigin $origin = null,
    ): void {
        if (isset($context['error'])) {
            self::error($logger, $collector, $context, $origin);
            return;
        }

        $status = $context['response']['status_code'] ?? null;
        if (is_int($status) && $status >= 500) {
            self::error($logger, $collector, $context, $origin);
        } elseif (is_int($status) && $status >= 400) {
            $origin === null
                ? $logger->warning($collector, $context)
                : $logger->warning($collector, $context, $origin);
        } else {
            $origin === null
                ? $logger->info($collector, $context)
                : $logger->info($collector, $context, $origin);
        }
    }

    /** @param array<string, mixed> $context */
    private static function error(
        CollectorLoggerInterface $logger,
        Collector $collector,
        array $context,
        ?LogOrigin $origin,
    ): void {
        $origin === null
            ? $logger->error($collector, $context)
            : $logger->error($collector, $context, $origin);
    }

    private function __construct() {}
}

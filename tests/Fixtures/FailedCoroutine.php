<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests\Fixtures;

/**
 * 仅在隔离进程中替代 Hyperf 调度器，覆盖真实引擎难以稳定触发的返回失败值路径。
 */
final class FailedCoroutine
{
    public static function inCoroutine(): bool
    {
        return true;
    }

    /** @param list<string> $keys */
    public static function fork(callable $callable, array $keys = []): int
    {
        return -1;
    }
}

<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/** trace_log.write_mode 接受的稳定字符串值。 */
final class WriteMode
{
    public const SYNC = 'sync';
    public const ASYNC = 'async';

    /** 常量容器不允许实例化。 */
    private function __construct() {}
}

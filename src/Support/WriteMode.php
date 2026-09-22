<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/** trace_log.write_mode 接受的稳定字符串值。 */
final class WriteMode
{
    public const string SYNC = 'sync';
    public const string ASYNC = 'async';

    private function __construct() {}
}

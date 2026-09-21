<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/** Supported trace_log.write_mode values. */
final class WriteMode
{
    public const SYNC = 'sync';
    public const ASYNC = 'async';

    private function __construct() {}
}

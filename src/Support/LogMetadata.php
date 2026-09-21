<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Enum\Collector;

/** Internal immutable formatter metadata captured at submission time. */
final readonly class LogMetadata
{
    public function __construct(public Collector $collector, public ?string $requestId, public int $coroutineId) {}
}

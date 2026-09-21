<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/** Immutable request and coroutine identity captured where an operation starts. */
final readonly class LogOrigin
{
    public function __construct(public ?string $requestId, public int $coroutineId) {}
}

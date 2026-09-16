<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/** 一次流快照结果；contents 为 null 时 protection 说明正文被省略的原因。 */
final readonly class PayloadSnapshot
{
    public function __construct(
        public ?string $contents,
        public ?PayloadProtection $protection = null,
    ) {}
}

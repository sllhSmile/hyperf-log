<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/** 一次流快照结果；contents 为 null 时 protection 说明正文被省略的原因。 */
final readonly class PayloadSnapshot
{
    /** 表示成功取得正文，或携带正文被省略的结构化原因。 */
    public function __construct(
        public ?string $contents,
        public ?PayloadProtection $protection = null,
    ) {}
}

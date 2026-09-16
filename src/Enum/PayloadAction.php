<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Enum;

/** payload_protection 中对原值执行的处理动作。 */
enum PayloadAction: string
{
    case Truncated = 'truncated';
    case Omitted = 'omitted';
}

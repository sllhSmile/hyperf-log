<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Enum;

/** payload 被截断或省略的稳定原因码。 */
enum PayloadReason: string
{
    case InvalidJson = 'invalid_json';
    case LimitExceeded = 'limit_exceeded';
    case NonRewindableStream = 'non_rewindable_stream';
    case ReadFailed = 'read_failed';
}

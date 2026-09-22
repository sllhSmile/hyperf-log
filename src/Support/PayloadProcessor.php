<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Enum\Collector;

/** 先对 HTTP server/client 字段脱敏，再对所有采集器执行容量限制。 */
final readonly class PayloadProcessor implements PayloadProcessorInterface
{
    /** 组合字段脱敏与容量限制，保持两种保护动作顺序稳定。 */
    public function __construct(private PayloadRedactor $redactor, private PayloadLimiter $limiter) {}

    /** HTTP 上下文先脱敏再限长，数据库和 Redis 仅执行容量限制。 */
    public function process(Collector $collector, array $context): array
    {
        if (in_array($collector, [Collector::Api, Collector::Sdk], true)) {
            $context = $this->redactor->redact($context);
        }

        return $this->limiter->limit($collector, $context);
    }
}

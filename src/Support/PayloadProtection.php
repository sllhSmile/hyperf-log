<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Sllhsmile\HyperfLog\Enum\PayloadAction;
use Sllhsmile\HyperfLog\Enum\PayloadReason;

/** 描述一次截断或省略动作，供日志使用者判断 payload 是否完整。 */
final readonly class PayloadProtection
{
    /** 创建一条稳定的 payload 截断或省略记录。 */
    public function __construct(
        public string $path,
        public PayloadAction $action,
        public PayloadReason $reason,
        public ?int $limitBytes = null,
        public ?int $originalBytes = null,
    ) {}

    /** 转为 JSON Schema 约定的保护动作结构。
     * @return array{path:string,action:string,reason:string,limit_bytes?:int,original_bytes?:int}
     */
    public function toArray(): array
    {
        $result = [
            'path' => $this->path,
            'action' => $this->action->value,
            'reason' => $this->reason->value,
        ];

        if ($this->limitBytes !== null) {
            $result['limit_bytes'] = $this->limitBytes;
        }
        if ($this->originalBytes !== null) {
            $result['original_bytes'] = $this->originalBytes;
        }

        return $result;
    }
}

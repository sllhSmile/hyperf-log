<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Context;

use Hyperf\Context\Context;
use Ramsey\Uuid\Uuid;

/**
 * 管理当前 Hyperf 协程中的请求链路标识和开始时间。
 *
 * start() 是唯一写入口，每次调用都会覆盖当前执行单元的旧链路；其余方法均为
 * 无副作用查询。固定使用类名作为 Context key，避免宿主配置不同导致读写错位。
 */
final class RequestContext
{
    public const CONTEXT_KEY = self::class;
    public const MAX_REQUEST_ID_BYTES = 128;

    /**
     * 开始一条新链路；空值或不符合安全 Header 约束的值会生成 UUID v7。
     * 有效 ID 必须是最多 128 字节、无空白的可打印 ASCII 字符串。
     */
    public function start(?string $requestId = null): TraceContext
    {
        $requestId = $this->normalizeRequestId($requestId);
        $trace = new TraceContext(
            $requestId,
            microtime(true),
        );

        return Context::set(self::CONTEXT_KEY, $trace);
    }

    /** 将外部 request-id 限制为可安全回显和透传的单行 Header 值。 */
    private function normalizeRequestId(?string $requestId): string
    {
        if ($requestId !== null
            && $requestId !== ''
            && strlen($requestId) <= self::MAX_REQUEST_ID_BYTES
            && preg_match('/^[\x21-\x7E]+$/D', $requestId) === 1) {
            return $requestId;
        }

        return Uuid::uuid7()->toString();
    }

    /** 返回当前链路快照；尚未 start() 时返回 null，且不会隐式创建。 */
    public function current(): ?TraceContext
    {
        $trace = Context::get(self::CONTEXT_KEY);

        return $trace instanceof TraceContext ? $trace : null;
    }

    /** 单独查询当前 request-id；不会生成新 ID。 */
    public function id(): ?string
    {
        return $this->current()?->requestId;
    }

    /** 单独查询当前链路开始时间；不会重置计时。 */
    public function startTime(): ?float
    {
        return $this->current()?->startedAt;
    }
}

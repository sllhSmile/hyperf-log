<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\Context\Context;
use Ramsey\Uuid\Uuid;

/**
 * 管理当前协程中不可分割的 trace 生命周期。
 */
class RequestContext
{
    /** Hyperf Context 中保存完整 trace 值对象的内部唯一键。 */
    public const CONTEXT_KEY = self::class;

    /**
     * 开始一条新 trace，并原子覆盖上一条 trace 的 ID 与开始时间。
     */
    public function start(?string $requestId = null): TraceContextData
    {
        $requestId = trim($requestId ?? '');
        if ($requestId === '') {
            $requestId = Uuid::uuid7()->toString();
        }

        $trace = new TraceContextData($requestId, microtime(true));

        return Context::set(self::CONTEXT_KEY, $trace);
    }

    /**
     * 查询当前 trace；未在执行单元入口调用 start() 时返回 null，且不隐式修改上下文。
     */
    public function current(): ?TraceContextData
    {
        $trace = Context::get(self::CONTEXT_KEY);

        return $trace instanceof TraceContextData ? $trace : null;
    }

    /**
     * 查询当前 request ID；未初始化 trace 时返回 null。
     */
    public function id(): ?string
    {
        return $this->current()?->requestId;
    }

    /**
     * 查询当前 trace 开始时间；未初始化 trace 时返回 null。
     */
    public function startTime(): ?float
    {
        return $this->current()?->startedAt;
    }
}

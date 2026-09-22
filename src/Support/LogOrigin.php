<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

/**
 * 操作发起位置的不可变链路快照。
 *
 * Promise 回调可能在另一协程完成；显式传递该对象可避免完成时误读其他协程的 Context。
 * requestId 在没有链路上下文时为 null，coroutineId 在非协程环境遵循 Hyperf 语义为 -1。
 */
final readonly class LogOrigin
{
    /** 固化操作发起时的 request-id 和协程 ID，允许跨协程传递。 */
    public function __construct(public ?string $requestId, public int $coroutineId) {}
}

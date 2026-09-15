<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * 在不改变 PSR-7 Stream 可观察状态的前提下获取日志快照。
 *
 * 日志属于旁路能力，不能为了读取 Body 消耗不可回绕的业务流。支持 seek 的 Stream 会
 * 从头读取并恢复调用前的位置；Hyperf SwooleStream 的 getContents() 不会消费内容，也可
 * 直接安全读取。配置容量上限后，已知大小的流会在读取前检查，其余流最多读取上限加一
 * 个字节；超限内容不保留不完整预览，避免截断后的 JSON 无法脱敏却仍写入敏感片段。
 */
final class StreamSnapshotter
{
    /**
     * 返回完整且未超限的内容；无法安全读取或超过容量上限时 contents 为 null。
     */
    public function snapshot(StreamInterface $stream, ?int $maxBytes = null): StreamSnapshot
    {
        $position = null;
        $contents = null;
        $size = null;

        try {
            if (! $stream->isSeekable()) {
                if (! $stream instanceof SwooleStream) {
                    return new StreamSnapshot(null);
                }

                $size = $stream->getSize();
                if ($maxBytes !== null && $size !== null && $size > $maxBytes) {
                    return new StreamSnapshot(null, true, $size);
                }

                $contents = $stream->getContents();
            } else {
                $position = $stream->tell();
                $size = $stream->getSize();
                if ($maxBytes !== null && $size !== null && $size > $maxBytes) {
                    return new StreamSnapshot(null, true, $size);
                }

                $stream->rewind();
                $contents = $maxBytes === null
                    ? $stream->getContents()
                    : $this->readUpTo($stream, $maxBytes + 1);
            }
        } catch (Throwable) {
            // 读取失败仍会在下方尝试恢复已经取得的原始位置。
        }

        if ($position !== null) {
            try {
                $stream->seek($position);
            } catch (Throwable) {
                // Stream 声明可回绕却恢复失败时也不能让日志异常中断业务流程。
                return new StreamSnapshot(null);
            }
        }

        if ($contents !== null && $maxBytes !== null && strlen($contents) > $maxBytes) {
            return new StreamSnapshot(null, true, $size);
        }

        return new StreamSnapshot($contents);
    }

    /**
     * StreamInterface::read() 允许短读，因此循环到 EOF 或达到目标字节数。
     */
    private function readUpTo(StreamInterface $stream, int $maxBytes): string
    {
        $contents = '';
        while (! $stream->eof() && strlen($contents) < $maxBytes) {
            $chunk = $stream->read($maxBytes - strlen($contents));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }
}

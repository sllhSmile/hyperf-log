<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Support;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\StreamInterface;
use Sllhsmile\HyperfLog\Enum\PayloadAction;
use Sllhsmile\HyperfLog\Enum\PayloadReason;
use Throwable;

/**
 * 以不消费业务内容为约束获取有界 PSR-7 Stream 快照。
 *
 * 可回绕流读取后恢复原位置；Hyperf SwooleStream 虽声明不可 seek，但 getContents()
 * 读取内存字符串且不会推进游标，因此可安全读取。其他不可回绕流一律省略，避免日志
 * 采集提前消费网络或生成器流。读取或复位失败都转换为 PayloadProtection；复位失败
 * 表示该流已无法继续承诺原游标位置，因此会明确记录 read_failed。
 */
final readonly class PayloadSnapshotter
{
    /** 注入动态单字段容量限制，不缓存请求期 Stream。 */
    public function __construct(private LogConfig $config) {}

    /** 在不改变业务读取位置的前提下取得有界正文快照。 */
    public function snapshot(StreamInterface $stream, string $path): PayloadSnapshot
    {
        $limit = $this->config->payloadMaxBytes();
        $position = null;
        $size = null;

        try {
            if (! $stream->isSeekable()) {
                // 仅对白名单内、已确认非消费式读取的 Hyperf 内存流开放快照。
                if (! $stream instanceof SwooleStream) {
                    return $this->omitted($path, PayloadReason::NonRewindableStream);
                }

                $size = $stream->getSize();
                if ($limit !== null && $size !== null && $size > $limit) {
                    return $this->omitted($path, PayloadReason::LimitExceeded, $limit, $size);
                }

                $contents = $stream->getContents();
            } else {
                $position = $stream->tell();
                $size = $stream->getSize();
                if ($limit !== null && $size !== null && $size > $limit) {
                    return $this->omitted($path, PayloadReason::LimitExceeded, $limit, $size);
                }

                $stream->rewind();
                $contents = $limit === null ? $stream->getContents() : $this->readUpTo($stream, $limit + 1);
            }
        } catch (Throwable) {
            $contents = null;
        }

        if ($position !== null) {
            try {
                $stream->seek($position);
            } catch (Throwable) {
                return $this->omitted($path, PayloadReason::ReadFailed);
            }
        }

        if ($contents === null) {
            return $this->omitted($path, PayloadReason::ReadFailed);
        }
        if ($limit !== null && strlen($contents) > $limit) {
            return $this->omitted($path, PayloadReason::LimitExceeded, $limit, $size);
        }

        return new PayloadSnapshot($contents);
    }

    /** 创建省略正文的快照，并附带机器可读的保护原因。 */
    private function omitted(
        string $path,
        PayloadReason $reason,
        ?int $limit = null,
        ?int $originalBytes = null,
    ): PayloadSnapshot {
        return new PayloadSnapshot(null, new PayloadProtection(
            $path,
            PayloadAction::Omitted,
            $reason,
            $limit,
            $originalBytes,
        ));
    }

    /** 最多读取指定字节，避免未知长度流被一次性完整加载。 */
    private function readUpTo(StreamInterface $stream, int $bytes): string
    {
        $contents = '';
        while (! $stream->eof() && strlen($contents) < $bytes) {
            $chunk = $stream->read($bytes - strlen($contents));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }
}

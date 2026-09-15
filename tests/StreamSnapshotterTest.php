<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use Hyperf\HttpMessage\Stream\SwooleStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Sllhsmile\HyperfLog\Support\StreamSnapshotter;

class StreamSnapshotterTest extends TestCase
{
    public function testItReadsSeekableStreamFromStartAndRestoresOriginalPosition(): void
    {
        $stream = Utils::streamFor('abcdef');
        $stream->seek(3);

        $snapshot = (new StreamSnapshotter())->snapshot($stream);

        self::assertSame('abcdef', $snapshot->contents);
        self::assertFalse($snapshot->truncated);
        self::assertSame(3, $stream->tell());
    }

    public function testItDoesNotConsumeNonSeekableStream(): void
    {
        $inner = Utils::streamFor('abcdef');
        $inner->seek(2);
        $stream = new NoSeekStream($inner);

        $snapshot = (new StreamSnapshotter())->snapshot($stream);

        self::assertNull($snapshot->contents);
        self::assertFalse($snapshot->truncated);
        self::assertSame(2, $inner->tell());
    }

    public function testItReadsSwooleStreamWithoutConsumingIt(): void
    {
        $stream = new SwooleStream('abcdef');

        $snapshot = (new StreamSnapshotter())->snapshot($stream, 6);

        self::assertSame('abcdef', $snapshot->contents);
        self::assertFalse($snapshot->truncated);
        self::assertSame('abcdef', $stream->getContents());
    }

    public function testItOmitsOversizedSwooleStreamWithoutConsumingIt(): void
    {
        $stream = new SwooleStream(str_repeat('a', 65));

        $snapshot = (new StreamSnapshotter())->snapshot($stream, 64);

        self::assertNull($snapshot->contents);
        self::assertTrue($snapshot->truncated);
        self::assertSame(65, $snapshot->originalBytes);
        self::assertSame(str_repeat('a', 65), $stream->getContents());
    }

    public function testItRestoresPositionWhenReadingFails(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('tell')->willReturn(4);
        $stream->expects(self::once())->method('rewind');
        $stream->expects(self::once())->method('getContents')->willThrowException(new \RuntimeException('read failed'));
        $stream->expects(self::once())->method('seek')->with(4);

        self::assertNull((new StreamSnapshotter())->snapshot($stream)->contents);
    }

    public function testRestoreFailureDoesNotEscapeIntoBusinessFlow(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('tell')->willReturn(1);
        $stream->expects(self::once())->method('rewind');
        $stream->method('getContents')->willReturn('body');
        $stream->expects(self::once())->method('seek')->with(1)->willThrowException(new \RuntimeException('seek failed'));

        self::assertNull((new StreamSnapshotter())->snapshot($stream)->contents);
    }

    public function testItOmitsKnownOversizedStreamWithoutReadingIt(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('tell')->willReturn(2);
        $stream->method('getSize')->willReturn(1024);
        $stream->expects(self::never())->method('rewind');
        $stream->expects(self::never())->method('read');

        $snapshot = (new StreamSnapshotter())->snapshot($stream, 64);

        self::assertNull($snapshot->contents);
        self::assertTrue($snapshot->truncated);
        self::assertSame(1024, $snapshot->originalBytes);
        self::assertSame(2, $stream->tell());
    }

    public function testItReadsOnlyOneByteBeyondLimitWhenSizeIsUnknown(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('tell')->willReturn(3);
        $stream->method('getSize')->willReturn(null);
        $stream->expects(self::once())->method('rewind');
        $stream->expects(self::once())->method('read')->with(17)->willReturn(str_repeat('a', 17));
        $stream->expects(self::once())->method('seek')->with(3);

        $snapshot = (new StreamSnapshotter())->snapshot($stream, 16);

        self::assertNull($snapshot->contents);
        self::assertTrue($snapshot->truncated);
        self::assertNull($snapshot->originalBytes);
    }
}

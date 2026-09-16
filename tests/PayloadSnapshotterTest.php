<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Stream\SwooleStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;

final class PayloadSnapshotterTest extends TestCase
{
    public function testSeekableStreamIsReadFromStartAndRestored(): void
    {
        $stream = Utils::streamFor('abcdef');
        $stream->seek(3);
        $snapshot = $this->snapshotter()->snapshot($stream, 'request.body');

        self::assertSame('abcdef', $snapshot->contents);
        self::assertNull($snapshot->protection);
        self::assertSame(3, $stream->tell());
    }

    public function testSwooleStreamIsSafelyReadWithoutConsumption(): void
    {
        $stream = new SwooleStream('abcdef');
        $snapshot = $this->snapshotter(6)->snapshot($stream, 'response.body');

        self::assertSame('abcdef', $snapshot->contents);
        self::assertSame('abcdef', $stream->getContents());
    }

    public function testNonRewindableStreamIsNotConsumed(): void
    {
        $inner = Utils::streamFor('abcdef');
        $inner->seek(2);
        $snapshot = $this->snapshotter()->snapshot(new NoSeekStream($inner), 'request.body');

        self::assertNull($snapshot->contents);
        self::assertSame('non_rewindable_stream', $snapshot->protection?->reason->value);
        self::assertSame(2, $inner->tell());
    }

    public function testKnownOversizedStreamIsOmittedBeforeReading(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('tell')->willReturn(2);
        $stream->method('getSize')->willReturn(100);
        $stream->expects(self::never())->method('rewind');

        $snapshot = $this->snapshotter(16)->snapshot($stream, 'request.body');

        self::assertNotNull($snapshot->protection);
        self::assertSame('limit_exceeded', $snapshot->protection->reason->value);
        self::assertSame(100, $snapshot->protection->originalBytes);
    }

    public function testReadFailureRestoresPositionAndReturnsProtection(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('tell')->willReturn(4);
        $stream->expects(self::once())->method('rewind');
        $stream->method('getContents')->willThrowException(new \RuntimeException('failed'));
        $stream->expects(self::once())->method('seek')->with(4);

        $snapshot = $this->snapshotter()->snapshot($stream, 'response.body');
        self::assertSame('read_failed', $snapshot->protection?->reason->value);
    }

    private function snapshotter(?int $maxBytes = null): PayloadSnapshotter
    {
        return new PayloadSnapshotter(new LogConfig(new Config([
            'trace_log' => ['payload' => ['max_bytes' => $maxBytes]],
        ])));
    }
}

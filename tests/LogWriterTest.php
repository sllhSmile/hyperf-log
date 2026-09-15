<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

class LogWriterTest extends TestCase
{
    /**
     * 日志上下文必须先经过 PayloadProcessor，再交给 LoggerFactory 写入。
     */
    public function testItProcessesPayloadBeforeWriting(): void
    {
        $original = ['request' => ['password' => 'plain-secret']];
        $processed = ['request' => ['password' => '****']];

        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->expects(self::once())
            ->method('process')
            ->with('apilog', $original)
            ->willReturn($processed);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('apilog', $processed);

        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::once())->method('get')->with('apilog', 'apilog')->willReturn($logger);

        $config = new LogConfig(new Config([]));
        $writer = new LogWriter($factory, $config, new RequestContext(), $processor);

        $writer->info('apilog', $original);
    }
}

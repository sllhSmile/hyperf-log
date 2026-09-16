<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\CollectorLogger;
use Sllhsmile\HyperfLog\Support\LogConfig;

final class CollectorLoggerTest extends TestCase
{
    public function testItProcessesPayloadAndWritesToTheMappedChannel(): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->expects(self::once())->method('process')
            ->with(Collector::Api, ['request' => ['body' => 'raw']])
            ->willReturn(['request' => ['body' => 'safe']]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->with('http.server', [
            'request' => ['body' => 'safe'],
            Collector::LOG_CONTEXT_KEY => Collector::Api,
        ]);
        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::once())->method('get')->with('apilog', 'custom')->willReturn($psrLogger);
        $config = new LogConfig(new Config(['trace_log' => ['logger_channel' => 'custom']]));

        (new CollectorLogger($factory, $config, new RequestContext(), $processor))
            ->info(Collector::Api, ['request' => ['body' => 'raw']]);
    }

    public function testItUsesHyperfDefaultChannelWhenLoggerChannelIsNull(): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->with('http.client', [
            Collector::LOG_CONTEXT_KEY => Collector::Sdk,
        ]);
        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::once())->method('get')->with('sdklog', null)->willReturn($psrLogger);

        (new CollectorLogger($factory, new LogConfig(new Config([])), new RequestContext(), $processor))
            ->info(Collector::Sdk, []);
    }

    public function testWriteFailureNeverEscapesIntoBusinessCode(): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willThrowException(new RuntimeException('processor failed'));
        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::never())->method('get');
        $config = new LogConfig(new Config([]));

        (new CollectorLogger($factory, $config, new RequestContext(), $processor))->info(Collector::Redis, []);
    }
}

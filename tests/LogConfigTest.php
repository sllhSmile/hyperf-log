<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogConfig;

final class LogConfigTest extends TestCase
{
    public function testCollectorsUseTheDedicatedConfigurationTree(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['collectors' => [
            'api' => ['enabled' => true, 'response_enabled' => false],
        ]]]));

        self::assertTrue($config->enabled(Collector::Api));
        self::assertFalse($config->responseEnabled(Collector::Api));
        self::assertFalse($config->enabled(Collector::Redis));
        self::assertTrue($config->anyEnabled());
    }

    public function testDefaultsAreSafeAndApiResponseIsEnabled(): void
    {
        $config = new LogConfig(new Config([]));

        self::assertFalse($config->anyEnabled());
        self::assertTrue($config->responseEnabled(Collector::Api));
        self::assertFalse($config->responseEnabled(Collector::Sdk));
        self::assertNull($config->loggerChannel());
        self::assertSame('x-b3-traceid', $config->requestIdHeader());
    }

    public function testLoggerChannelCanSelectAnExistingHyperfChannel(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['logger_channel' => ' daily ']]));

        self::assertSame('daily', $config->loggerChannel());
    }

    public function testInvalidLoggerChannelFailsFast(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['logger_channel' => false]]));

        $this->expectException(\InvalidArgumentException::class);
        $config->loggerChannel();
    }

    public function testInvalidPayloadConfigurationFailsFast(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['payload' => ['max_bytes' => 0]]]));
        $this->expectException(\InvalidArgumentException::class);
        $config->payloadMaxBytes();
    }
}

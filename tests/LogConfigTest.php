<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Support\LogConfig;

/**
 * 验证 Hyperf 新式 logger.channels 配置可被公共包正确读取。
 */
class LogConfigTest extends TestCase
{
    /**
     * LoggerFactory 直接读取 logger.channels 中的公共包 channel 配置。
     */
    public function testItReadsNewStyleLoggerChannelConfiguration(): void
    {
        $config = new LogConfig(new Config(['logger' => [
            'default' => 'default',
            'channels' => [
                'apilog' => ['enabled' => true],
            ],
        ]]));

        self::assertTrue($config->enabled('apilog'));
        self::assertSame('apilog', $config->channel('apilog'));
        self::assertTrue($config->anyEnabled());
    }

    /**
     * 所有采集器默认关闭，避免安装公共包后产生额外日志。
     */
    public function testCollectorsAreDisabledByDefault(): void
    {
        $config = new LogConfig(new Config([]));

        self::assertFalse($config->anyEnabled());
        self::assertFalse($config->enabled('dblog'));
        self::assertSame('x-b3-traceid', $config->requestIdHeader());
    }

    /**
     * 验证 trace_log 可覆盖 Guzzle 的默认超时配置。
     */
    public function testItReadsTraceLogGuzzleConfiguration(): void
    {
        $config = new LogConfig(new Config([
            'trace_log' => [
                'request_start_header' => 'x-call-started-at',
                'guzzle' => [
                    'timeout' => 5,
                    'connect_timeout' => 2,
                ],
            ],
        ]));

        self::assertSame('x-call-started-at', $config->requestStartHeader());
        self::assertSame(5.0, $config->guzzleTimeout());
        self::assertSame(2.0, $config->guzzleConnectTimeout());
    }

    /**
     * 未声明公共默认值时，不能覆盖 Guzzle 的内置超时行为。
     */
    public function testItLeavesGuzzleTimeoutsUnsetByDefault(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['guzzle' => []]]));

        self::assertNull($config->guzzleTimeout());
        self::assertNull($config->guzzleConnectTimeout());
    }

    public function testItReadsPayloadProtectionDefaultsAndOverrides(): void
    {
        $defaults = new LogConfig(new Config([]));
        self::assertContains('password', $defaults->payloadSensitiveFields());
        self::assertSame('****', $defaults->payloadRedactionValue());
        self::assertSame(64 * 1024, $defaults->payloadMaxBytes());

        $configured = new LogConfig(new Config(['trace_log' => ['payload' => [
            'sensitive_fields' => ['PIN', 'pin'],
            'redaction_value' => '[hidden]',
            'max_bytes' => null,
        ]]]));
        self::assertSame(['pin'], $configured->payloadSensitiveFields());
        self::assertSame('[hidden]', $configured->payloadRedactionValue());
        self::assertNull($configured->payloadMaxBytes());
    }

    /**
     * 验证大响应记录默认关闭，并可按各自 logger channel 单独开启。
     */
    public function testItReadsResponseEnabledFromLoggerChannel(): void
    {
        $config = new LogConfig(new Config(['logger' => [
            'default' => 'default',
            'channels' => [
                'dblog' => ['response_enabled' => true],
                'redislog' => ['response_enabled' => true],
                'sdklog' => ['response_enabled' => false],
            ],
        ]]));

        self::assertTrue($config->responseEnabled('dblog'));
        self::assertTrue($config->responseEnabled('redislog'));
        self::assertFalse($config->responseEnabled('sdklog'));
        self::assertFalse((new LogConfig(new Config([])))->responseEnabled('dblog'));
    }
}

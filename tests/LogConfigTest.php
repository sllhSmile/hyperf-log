<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\WriteMode;

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

    #[DataProvider('invalidCollectorBooleans')]
    public function testCollectorFlagsRejectNonBooleanValues(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("trace_log.collectors.database.{$key} must be a boolean");

        new LogConfig(new Config(['trace_log' => ['collectors' => [
            'database' => [$key => $value],
        ]]]));
    }

    /** @return list<array{string, mixed}> */
    public static function invalidCollectorBooleans(): array
    {
        return [
            ['enabled', 'false'],
            ['enabled', 1],
            ['response_enabled', 'true'],
            ['response_enabled', null],
        ];
    }

    public function testDefaultsAreSafeAndApiResponseIsEnabled(): void
    {
        $config = new LogConfig(new Config([]));

        self::assertFalse($config->anyEnabled());
        self::assertTrue($config->responseEnabled(Collector::Api));
        self::assertFalse($config->responseEnabled(Collector::Sdk));
        self::assertNull($config->loggerChannel());
        self::assertSame('x-b3-traceid', $config->requestIdHeader());
        self::assertSame(WriteMode::SYNC, $config->writeMode());
        self::assertSame(8 * 1024 * 1024, $config->asyncMaxBufferBytes());
    }

    public function testLoggerChannelCanSelectAnExistingHyperfChannel(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['logger_channel' => ' daily ']]));

        self::assertSame('daily', $config->loggerChannel());
    }

    public function testConfigurationIsSnapshottedAtConstruction(): void
    {
        $values = new Config([
            'app_name' => 'original-service',
            'trace_log' => [
                'write_mode' => WriteMode::SYNC,
                'collectors' => ['api' => ['enabled' => true, 'response_enabled' => false]],
            ],
        ]);
        $config = new LogConfig($values);

        $values->set('app_name', 'changed-service');
        $values->set('trace_log.write_mode', WriteMode::ASYNC);
        $values->set('trace_log.collectors.api.enabled', false);
        $values->set('trace_log.collectors.api.response_enabled', true);

        self::assertSame('original-service', $config->service());
        self::assertSame(WriteMode::SYNC, $config->writeMode());
        self::assertTrue($config->enabled(Collector::Api));
        self::assertFalse($config->responseEnabled(Collector::Api));
    }

    public function testInvalidLoggerChannelFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogConfig(new Config(['trace_log' => ['logger_channel' => false]]));
    }

    #[DataProvider('invalidRequestIdHeaders')]
    public function testInvalidRequestIdHeaderFailsFast(mixed $header): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trace_log.request_id_header');

        new LogConfig(new Config(['trace_log' => ['request_id_header' => $header]]));
    }

    /** @return list<array{mixed}> */
    public static function invalidRequestIdHeaders(): array
    {
        return [[''], ['x trace id'], ["x-trace\r\nid"], [false], [null]];
    }

    public function testInvalidPayloadConfigurationFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogConfig(new Config(['trace_log' => ['payload' => ['max_bytes' => 0]]]));
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidConstructionConfiguration')]
    public function testAllConfigurationIsValidatedDuringConstruction(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new LogConfig(new Config($values));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConstructionConfiguration(): iterable
    {
        yield 'service' => [['app_name' => []], 'app_name'];
        yield 'sensitive fields container' => [
            ['trace_log' => ['payload' => ['sensitive_fields' => 'password']]],
            'trace_log.payload.sensitive_fields',
        ];
        yield 'sensitive field entry' => [
            ['trace_log' => ['payload' => ['sensitive_fields' => ['password', '']]]],
            'trace_log.payload.sensitive_fields',
        ];
        yield 'redaction value' => [
            ['trace_log' => ['payload' => ['redaction_value' => false]]],
            'trace_log.payload.redaction_value',
        ];
    }

    public function testWriteModeCanSelectSynchronousWrites(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['write_mode' => WriteMode::SYNC]]));

        self::assertSame(WriteMode::SYNC, $config->writeMode());
    }

    public function testAsyncBufferBudgetCanBeConfigured(): void
    {
        $config = new LogConfig(new Config(['trace_log' => ['async' => ['max_buffer_bytes' => 4096]]]));

        self::assertSame(4096, $config->asyncMaxBufferBytes());
    }

    /** @return list<array{mixed}> */
    public static function invalidAsyncBufferBudgets(): array
    {
        return [[1023], [0], [-1], ['8192'], [null], [false], [[]]];
    }

    #[DataProvider('invalidAsyncBufferBudgets')]
    public function testInvalidAsyncBufferBudgetFailsFast(mixed $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trace_log.async.max_buffer_bytes');
        new LogConfig(new Config(['trace_log' => ['async' => ['max_buffer_bytes' => $bytes]]]));
    }

    /** @return list<array{mixed}> */
    public static function invalidWriteModes(): array
    {
        return [['ASYNC'], [' sync '], [''], ['invalid'], [null], [false], [1], [[]]];
    }

    #[DataProvider('invalidWriteModes')]
    public function testInvalidWriteModeFailsFast(mixed $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trace_log.write_mode');
        new LogConfig(new Config(['trace_log' => ['write_mode' => $mode]]));
    }

}

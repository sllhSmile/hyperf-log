<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadLimiter;
use Sllhsmile\HyperfLog\Support\PayloadProcessor;
use Sllhsmile\HyperfLog\Support\PayloadRedactor;

final class PayloadProcessorTest extends TestCase
{
    public function testItNormalizesHeadersAndRecursivelyRedactsHttpPayloads(): void
    {
        $result = $this->processor()->process(Collector::Api, [
            'request' => [
                'url' => 'https://example.test?a=1&token=secret',
                'headers' => ['Authorization' => ['Bearer secret'], 'Content-Type' => ['application/json']],
                'body' => '{"profile":{"password":"secret"}}',
            ],
        ]);

        self::assertSame(['****'], $result['request']['headers']['authorization']);
        self::assertArrayNotHasKey('Authorization', $result['request']['headers']);
        self::assertSame('https://example.test?a=1&token=%2A%2A%2A%2A', $result['request']['url']);
        self::assertSame('****', $result['request']['body']['profile']['password']);
    }

    public function testMalformedDeclaredJsonIsOmittedFailClosed(): void
    {
        $result = $this->processor()->process(Collector::Sdk, [
            'response' => ['headers' => ['Content-Type' => ['application/problem+json']], 'body' => '{"token":"secret"'],
        ]);

        self::assertArrayNotHasKey('body', $result['response']);
        self::assertSame([
            'path' => 'response.body', 'action' => 'omitted', 'reason' => 'invalid_json',
        ], $result['payload_protection'][0]);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBusinessFieldNamedHeadersKeepsItsJsonShape(): void
    {
        $result = $this->processor()->process(Collector::Api, [
            'request' => [
                'headers' => ['Content-Type' => ['application/json']],
                'body' => '{"headers":{"nested":{"token":"secret"}}}',
            ],
        ]);

        self::assertSame('****', $result['request']['body']['headers']['nested']['token']);
        self::assertSame(['application/json'], $result['request']['headers']['content-type']);
    }

    #[DataProvider('jsonScalarProvider')]
    public function testValidJsonPreservesEveryJsonType(string $json, mixed $expected): void
    {
        $result = $this->processor()->process(Collector::Api, [
            'request' => ['headers' => ['content-type' => ['application/json']], 'body' => $json],
        ]);

        self::assertSame($expected, $result['request']['body']);
    }

    /** @return list<array{string, mixed}> */
    public static function jsonScalarProvider(): array
    {
        return [['null', null], ['true', true], ['42', 42], ['"value"', 'value'], ['[]', []]];
    }

    public function testItTruncatesStringsAndOmitsOversizedStructures(): void
    {
        $result = $this->processor(12)->process(Collector::Redis, [
            'request' => ['command' => str_repeat('x', 20)],
            'response' => ['body' => ['secret' => str_repeat('x', 20)]],
        ]);

        self::assertSame('xxxxxxxxx...', $result['request']['command']);
        self::assertArrayNotHasKey('body', $result['response']);
        self::assertSame('truncated', $result['payload_protection'][0]['action']);
        self::assertSame('omitted', $result['payload_protection'][1]['action']);
    }

    public function testDatabasePayloadIsNotFieldRedacted(): void
    {
        $result = $this->processor()->process(Collector::Database, [
            'request' => ['sql' => "select 'password'"],
        ]);

        self::assertSame("select 'password'", $result['request']['sql']);
    }

    private function processor(?int $maxBytes = null): PayloadProcessor
    {
        $config = new LogConfig(new Config(['trace_log' => ['payload' => ['max_bytes' => $maxBytes]]]));

        return new PayloadProcessor(new PayloadRedactor($config), new PayloadLimiter($config));
    }
}

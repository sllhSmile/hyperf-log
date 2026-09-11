<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadProcessor;

class PayloadProcessorTest extends TestCase
{
    public function testItRedactsHeadersQueryJsonAndResponseFields(): void
    {
        $processor = $this->processor();

        $result = $processor->process('apilog', [
            'request' => [
                'url' => 'https://example.com/users?token=secret&name=smile&profile[password]=123',
                'headers' => [
                    'Authorization' => ['Bearer secret'],
                    'Content-Type' => ['application/json'],
                ],
                'options' => json_encode([
                    'name' => 'smile',
                    'password' => '123456',
                    'profile' => ['access_token' => 'token-value'],
                ], JSON_THROW_ON_ERROR),
            ],
            'response' => [
                'id' => 1,
                'token' => 'response-token',
            ],
        ]);

        self::assertSame(['****'], $result['request']['headers']['Authorization']);
        self::assertSame(['application/json'], $result['request']['headers']['Content-Type']);
        self::assertSame(
            'https://example.com/users?token=%2A%2A%2A%2A&name=smile&profile[password]=%2A%2A%2A%2A',
            $result['request']['url'],
        );
        self::assertSame([
            'name' => 'smile',
            'password' => '****',
            'profile' => ['access_token' => '****'],
        ], json_decode($result['request']['options'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['id' => 1, 'token' => '****'], $result['response']);
        self::assertArrayNotHasKey('payload_truncation', $result);
    }

    public function testItUsesConfiguredFieldsAndReplacement(): void
    {
        $processor = $this->processor([
            'sensitive_fields' => ['pin'],
            'redaction_value' => '[hidden]',
            'max_bytes' => null,
        ]);

        $result = $processor->process('sdklog', [
            'request' => [
                'url' => 'https://example.com?pin=1234&token=kept',
                'headers' => [],
                'options' => '{"pin":"1234","password":"kept"}',
            ],
            'response' => null,
        ]);

        self::assertSame('https://example.com?pin=%5Bhidden%5D&token=kept', $result['request']['url']);
        self::assertSame(
            ['pin' => '[hidden]', 'password' => 'kept'],
            json_decode($result['request']['options'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testItRedactsFormEncodedRequestBody(): void
    {
        $result = $this->processor()->process('apilog', [
            'request' => [
                'url' => 'https://example.com/login',
                'headers' => ['Content-Type' => ['application/x-www-form-urlencoded; charset=UTF-8']],
                'options' => 'username=smile&password=123&profile[token]=abc',
            ],
            'response' => null,
        ]);

        self::assertSame(
            'username=smile&password=%2A%2A%2A%2A&profile[token]=%2A%2A%2A%2A',
            $result['request']['options'],
        );
    }

    public function testItTruncatesPayloadsAndAddsMetadata(): void
    {
        $processor = $this->processor([
            'sensitive_fields' => [],
            'redaction_value' => '****',
            'max_bytes' => 16,
        ]);

        $result = $processor->process('redislog', [
            'request' => [
                'command' => 'GET example',
                'parameters' => [str_repeat('a', 40)],
            ],
            'response' => str_repeat('中', 20),
        ]);

        self::assertLessThanOrEqual(16, strlen($result['request']['parameters']));
        self::assertLessThanOrEqual(16, strlen($result['response']));
        self::assertSame(44, $result['payload_truncation']['request.parameters']['original_bytes']);
        self::assertSame(60, $result['payload_truncation']['response']['original_bytes']);
    }

    public function testItAlwaysRedactsRedisAuthParameters(): void
    {
        $processor = $this->processor([
            'sensitive_fields' => [],
            'redaction_value' => '[secret]',
            'max_bytes' => null,
        ]);

        $result = $processor->process('redislog', [
            'request' => [
                'command' => 'AUTH ***',
                'parameters' => ['default', 'redis-password'],
            ],
        ]);

        self::assertSame(['[secret]', '[secret]'], $result['request']['parameters']);
    }

    public function testItLeavesDatabaseLogsUnchanged(): void
    {
        $processor = $this->processor([
            'sensitive_fields' => ['password'],
            'redaction_value' => '****',
            'max_bytes' => 8,
        ]);
        $context = [
            'request' => [
                'sql' => "select * from users where password = 'plain-secret'",
            ],
            'response' => str_repeat('x', 20),
        ];

        self::assertSame($context, $processor->process('dblog', $context));
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function processor(?array $payload = null): PayloadProcessor
    {
        $configuration = $payload === null ? [] : ['trace_log' => ['payload' => $payload]];

        return new PayloadProcessor(new LogConfig(new Config($configuration)));
    }
}

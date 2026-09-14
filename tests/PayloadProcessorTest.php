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
                'body' => json_encode([
                    'name' => 'smile',
                    'password' => '123456',
                    'profile' => ['access_token' => 'token-value'],
                ], JSON_THROW_ON_ERROR),
            ],
            'response' => [
                'body' => json_encode([
                    'id' => 1,
                    'token' => 'response-token',
                ], JSON_THROW_ON_ERROR),
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
        ], $result['request']['body']);
        self::assertSame(
            ['id' => 1, 'token' => '****'],
            $result['response']['body'],
        );
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
                'body' => '{"pin":"1234","password":"kept"}',
            ],
            'response' => null,
        ]);

        self::assertSame('https://example.com?pin=%5Bhidden%5D&token=kept', $result['request']['url']);
        self::assertSame(
            ['pin' => '[hidden]', 'password' => 'kept'],
            $result['request']['body'],
        );
    }

    public function testItPreservesJsonStructureWhenRedactionIsDisabled(): void
    {
        $processor = $this->processor([
            'sensitive_fields' => [],
            'redaction_value' => '****',
            'max_bytes' => null,
        ]);

        $result = $processor->process('apilog', [
            'request' => [
                'headers' => ['Content-Type' => ['application/json']],
                'body' => '{"token":"plain-token"}',
            ],
            'response' => ['body' => '{"ok":true}'],
        ]);

        self::assertSame(['token' => 'plain-token'], $result['request']['body']);
        self::assertSame(['ok' => true], $result['response']['body']);
    }

    public function testItKeepsPlainTextBodyAsString(): void
    {
        $result = $this->processor()->process('apilog', [
            'request' => [
                'headers' => ['Content-Type' => ['text/plain']],
                'body' => '{"looks":"like json"}',
            ],
            'response' => [
                'headers' => ['Content-Type' => ['text/plain']],
                'body' => '{"also":"json-looking"}',
            ],
        ]);

        self::assertSame('{"looks":"like json"}', $result['request']['body']);
        self::assertSame('{"also":"json-looking"}', $result['response']['body']);
    }

    public function testItKeepsJsonLookingRedisResultAsString(): void
    {
        $result = $this->processor()->process('redislog', [
            'request' => ['command' => 'GET cached-json'],
            'response' => ['body' => '{"token":"stored-value"}'],
        ]);

        self::assertSame('{"token":"stored-value"}', $result['response']['body']);
    }

    public function testItRedactsFormEncodedRequestBody(): void
    {
        $result = $this->processor()->process('apilog', [
            'request' => [
                'url' => 'https://example.com/login',
                'headers' => ['Content-Type' => ['application/x-www-form-urlencoded; charset=UTF-8']],
                'body' => 'username=smile&password=123&profile[token]=abc',
            ],
            'response' => null,
        ]);

        self::assertSame(
            'username=smile&password=%2A%2A%2A%2A&profile[token]=%2A%2A%2A%2A',
            $result['request']['body'],
        );
    }

    public function testItRedactsParsedMultipartFieldsAndKeepsFileMetadata(): void
    {
        $result = $this->processor()->process('apilog', [
            'request' => [
                'url' => 'https://example.com/profile',
                'headers' => ['Content-Type' => ['multipart/form-data; boundary=test']],
                'body' => ['name' => 'smile', 'password' => 'plain-secret'],
                'files' => [
                    'avatar' => [
                        'filename' => 'avatar.jpg',
                        'media_type' => 'image/jpeg',
                        'size' => 12,
                        'error' => UPLOAD_ERR_OK,
                    ],
                ],
            ],
        ]);

        self::assertSame(
            ['name' => 'smile', 'password' => '****'],
            $result['request']['body'],
        );
        self::assertSame('avatar.jpg', $result['request']['files']['avatar']['filename']);
        self::assertSame(12, $result['request']['files']['avatar']['size']);
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
                'command' => 'SET key ' . str_repeat('a', 40),
            ],
            'response' => ['body' => str_repeat('中', 20)],
        ]);

        self::assertLessThanOrEqual(16, strlen($result['request']['command']));
        self::assertLessThanOrEqual(16, strlen($result['response']['body']));
        self::assertSame(48, $result['payload_truncation']['request.command']['original_bytes']);
        self::assertSame(60, $result['payload_truncation']['response.body']['original_bytes']);
    }

    public function testItLeavesDatabaseContentUnredactedButStillLimitsCapacity(): void
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
            'response' => ['body' => ['password' => 'plain-secret', 'value' => str_repeat('x', 20)]],
        ];

        $result = $processor->process('dblog', $context);

        self::assertLessThanOrEqual(8, strlen($result['request']['sql']));
        self::assertNull($result['response']['body']);
        self::assertStringStartsWith('selec', $result['request']['sql']);
        self::assertArrayHasKey('request.sql', $result['payload_truncation']);
        self::assertArrayHasKey('response.body', $result['payload_truncation']);
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

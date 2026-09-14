<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Hyperf\Config\Config;
use Hyperf\HttpServer\Event\RequestHandled;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Listener\ApiLogListener;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;
use Sllhsmile\HyperfLog\Support\StreamSnapshotter;

class ApiLogListenerTest extends TestCase
{
    public function testItLogsSeekableBodiesAndRestoresTheirOriginalPositions(): void
    {
        $requestBody = Utils::streamFor('{"name":"smile"}');
        $requestBody->seek(4);
        $responseBody = Utils::streamFor('{"ok":true}');
        $responseBody->seek(3);

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'apilog',
            self::callback(static fn (array $context): bool =>
                $context['request']['body'] === '{"name":"smile"}'
                && $context['response']['status_code'] === 200
                && $context['response']['body'] === '{"ok":true}'
                && $context['duration_ms'] === null),
        );

        $listener = $this->listener($writer);
        $listener->process(new RequestHandled(
            new ServerRequest('POST', '/users', [], $requestBody),
            new Response(200, [], $responseBody),
        ));

        self::assertSame(4, $requestBody->tell());
        self::assertSame(3, $responseBody->tell());
    }

    public function testItDoesNotConsumeNonSeekableRequestOrResponseBodies(): void
    {
        $requestBody = Utils::streamFor('request-secret');
        $requestBody->seek(2);
        $responseBody = Utils::streamFor('response-secret');
        $responseBody->seek(3);

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'apilog',
            self::callback(static fn (array $context): bool =>
                $context['request']['body'] === null
                && $context['response']['body'] === null),
        );

        $listener = $this->listener($writer);
        $listener->process(new RequestHandled(
            new ServerRequest('POST', '/users', [], new NoSeekStream($requestBody)),
            new Response(200, [], new NoSeekStream($responseBody)),
        ));

        self::assertSame(2, $requestBody->tell());
        self::assertSame(3, $responseBody->tell());
    }

    public function testItUsesParsedMultipartFieldsAndFileMetadataWithoutReadingRawBody(): void
    {
        $requestBody = Utils::streamFor('raw-password-and-file-content');
        $requestBody->seek(5);
        $request = (new ServerRequest(
            'POST',
            '/profile',
            ['Content-Type' => 'multipart/form-data; boundary=test-boundary'],
            $requestBody,
        ))
            ->withParsedBody(['name' => 'smile', 'password' => 'plain-secret'])
            ->withUploadedFiles([
                'avatar' => new UploadedFile(
                    Utils::streamFor('binary-image'),
                    12,
                    UPLOAD_ERR_OK,
                    'avatar.jpg',
                    'image/jpeg',
                ),
            ]);

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'apilog',
            self::callback(static fn (array $context): bool =>
                $context['request']['body'] === ['name' => 'smile', 'password' => 'plain-secret']
                && $context['request']['files'] === [
                    'avatar' => [
                        'filename' => 'avatar.jpg',
                        'media_type' => 'image/jpeg',
                        'size' => 12,
                        'error' => UPLOAD_ERR_OK,
                    ],
                ]),
        );

        $this->listener($writer)->process(new RequestHandled($request, new Response()));

        // multipart 使用 ServerRequest 已解析数据，不接触原始请求流。
        self::assertSame(5, $requestBody->tell());
    }

    public function testItOmitsOversizedBodyBeforeReadingAndReportsTruncation(): void
    {
        $requestBody = Utils::streamFor(str_repeat('a', 32));
        $requestBody->seek(4);

        $writer = $this->createMock(LogWriter::class);
        $writer->expects(self::once())->method('info')->with(
            'apilog',
            self::callback(static fn (array $context): bool =>
                $context['request']['body'] === null
                && $context['payload_truncation']['request.body'] === [
                    'limit_bytes' => 16,
                    'original_bytes' => 32,
                ]),
        );

        $this->listener($writer, 16)->process(new RequestHandled(
            new ServerRequest('POST', '/users', [], $requestBody),
            new Response(),
        ));

        self::assertSame(4, $requestBody->tell());
    }

    private function listener(LogWriter $writer, int $maxBytes = 64 * 1024): ApiLogListener
    {
        $config = new LogConfig(new Config([
            'logger' => ['channels' => ['apilog' => ['enabled' => true]]],
            'trace_log' => ['payload' => ['max_bytes' => $maxBytes]],
        ]));

        return new ApiLogListener(
            $config,
            $writer,
            new RequestContext($config),
            new StreamSnapshotter(),
        );
    }
}

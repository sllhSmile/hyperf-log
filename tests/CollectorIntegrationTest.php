<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Command\Command;
use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Config\Config;
use Hyperf\Database\Connection;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\HttpServer\Event\RequestHandled;
use Hyperf\Logger\Logger;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\RedisConnection;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Formatter\StructuredJsonFormatter;
use Sllhsmile\HyperfLog\Listener\ApiLogListener;
use Sllhsmile\HyperfLog\Listener\CommandTraceListener;
use Sllhsmile\HyperfLog\Listener\DatabaseLogListener;
use Sllhsmile\HyperfLog\Listener\RedisLogListener;
use Sllhsmile\HyperfLog\Support\CollectorLogger;
use Sllhsmile\HyperfLog\Support\GuzzleMiddlewareInstaller;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadLimiter;
use Sllhsmile\HyperfLog\Support\PayloadProcessor;
use Sllhsmile\HyperfLog\Support\PayloadRedactor;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;
use Sllhsmile\HyperfLog\Support\SdkLogContextBuilder;
use Sllhsmile\HyperfLog\Support\SqlInterpolator;

final class CollectorIntegrationTest extends TestCase
{
    /** @return list<array{string}> */
    public static function writeModes(): array
    {
        return [['async'], ['sync']];
    }

    #[DataProvider('writeModes')]
    public function testCommandTraceConnectsAllCollectorsToProtectedJson(string $mode): void
    {
        $config = new LogConfig(new Config(['app_name' => 'package-test', 'trace_log' => [
            'write_mode' => $mode,
            'collectors' => [
                'api' => ['enabled' => true, 'response_enabled' => true],
                'sdk' => ['enabled' => true, 'response_enabled' => true],
                'database' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]]));
        $requestContext = new RequestContext();
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new StructuredJsonFormatter($requestContext, $config));
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $name): Logger => new Logger($name, [$handler]));
        $logger = new CollectorLogger(
            $factory,
            $config,
            $requestContext,
            new PayloadProcessor(new PayloadRedactor($config), new PayloadLimiter($config)),
        );
        $snapshotter = new PayloadSnapshotter($config);
        $requestId = null;

        try {
            \Swoole\Coroutine\run(function () use ($config, $requestContext, $logger, $snapshotter, &$requestId): void {
                (new CommandTraceListener($requestContext))->process(new BeforeHandle($this->createMock(Command::class)));
                $requestId = $requestContext->id();
                $request = new ServerRequest(
                    'POST',
                    'https://example.test/?token=secret-query',
                    ['Content-Type' => 'application/json', 'Authorization' => 'secret-header'],
                    '{"password":"secret-body"}',
                );
                $response = new Response(200, ['Content-Type' => 'application/json'], '{"token":"secret-response"}');
                (new ApiLogListener($config, $logger, $requestContext, $snapshotter))
                    ->process(new RequestHandled($request, $response));

                $stack = HandlerStack::create(static fn() => Create::promiseFor($response));
                (new GuzzleMiddlewareInstaller(
                    $config,
                    $requestContext,
                    new SdkLogContextBuilder($config, $snapshotter),
                    $logger,
                ))->install($stack);
                (new Client(['handler' => $stack]))->post('https://example.test', [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => '{"password":"secret-sdk"}',
                ]);

                $connection = $this->createMock(Connection::class);
                $connection->method('prepareBindings')->willReturnArgument(0);
                $connection->method('getDatabaseName')->willReturn('testing');
                $connection->method('getName')->willReturn('default');
                (new DatabaseLogListener($config, $logger, new SqlInterpolator()))
                    ->process(new QueryExecuted('select ?', [7], 1.0, $connection));
                (new RedisLogListener($config, $logger))->process(new CommandExecuted(
                    'auth',
                    ['user', 'secret-redis'],
                    1.0,
                    $this->createMock(RedisConnection::class),
                    'default',
                    true,
                    null,
                ));
            });

            rewind($stream);
            $json = stream_get_contents($stream);
            self::assertIsString($json);
            self::assertStringNotContainsString('secret-', $json);
            $lines = explode("\n", trim($json));
            self::assertCount(4, $lines);
            $records = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
            self::assertEqualsCanonicalizing(
                ['http.server', 'http.client', 'database.query', 'redis.command'],
                array_column($records, 'type'),
            );
            foreach ($records as $record) {
                self::assertSame($requestId, $record['request_id']);
                self::assertSame(1, $record['schema_version']);
                self::assertSame('package-test', $record['service']);
                self::assertGreaterThan(0, $record['coroutine_id']);
            }
        } finally {
            $handler->close();
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}

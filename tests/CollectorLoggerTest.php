<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\CollectorLogger;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogMetadata;
use Sllhsmile\HyperfLog\Support\LogOrigin;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Channel;

final class CollectorLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItProcessesPayloadAndWritesToTheMappedChannel(): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->expects(self::once())->method('process')
            ->with(Collector::Api, ['request' => ['body' => 'raw']])
            ->willReturn(['request' => ['body' => 'safe']]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->with(
            'http.server',
            self::callback(static fn(array $context): bool =>
                $context['request']['body'] === 'safe'
                && $context[Collector::LOG_METADATA_KEY] instanceof LogMetadata
                && $context[Collector::LOG_METADATA_KEY]->collector === Collector::Api),
        );
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
        $psrLogger->expects(self::once())->method('info')->with(
            'http.client',
            self::callback(static fn(array $context): bool =>
                $context[Collector::LOG_METADATA_KEY] instanceof LogMetadata
                && $context[Collector::LOG_METADATA_KEY]->collector === Collector::Sdk),
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::once())->method('get')->with('sdklog', null)->willReturn($psrLogger);

        (new CollectorLogger($factory, new LogConfig(new Config([])), new RequestContext(), $processor))
            ->info(Collector::Sdk, []);
    }

    public function testExplicitOriginOverridesCurrentCoroutineContext(): void
    {
        $requestContext = new RequestContext();
        $requestContext->start('callback-trace');
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->with(
            'http.client',
            self::callback(static fn(array $context): bool =>
                $context[Collector::LOG_METADATA_KEY] instanceof LogMetadata
                && $context[Collector::LOG_METADATA_KEY]->origin->requestId === 'request-trace'
                && $context[Collector::LOG_METADATA_KEY]->origin->coroutineId === 123),
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);

        (new CollectorLogger($factory, new LogConfig(new Config([])), $requestContext, $processor))
            ->info(Collector::Sdk, [], new LogOrigin('request-trace', 123));
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

    public function testAllPsrLevelsDelegateToTheMatchingMonologLevel(): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            $psrLogger->expects(self::once())->method($method)->with(
                'http.server',
                self::callback(static fn(array $context): bool => $context[Collector::LOG_METADATA_KEY]->collector === Collector::Api),
            );
        }
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config(['trace_log' => ['write_mode' => 'sync']])), new RequestContext(), $processor);

        $logger->emergency(Collector::Api, []);
        $logger->alert(Collector::Api, []);
        $logger->critical(Collector::Api, []);
        $logger->error(Collector::Api, []);
        $logger->warning(Collector::Api, []);
        $logger->notice(Collector::Api, []);
        $logger->info(Collector::Api, []);
        $logger->debug(Collector::Api, []);
    }

    public function testAsyncModeUsesOneConsumerAndSnapshotsRequestContext(): void
    {
        $requestContext = new RequestContext();
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $writerCoroutineId = null;
        $writerRequestId = null;
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->willReturnCallback(
            function (string $message, array $context) use (&$writerCoroutineId, &$writerRequestId): void {
                $writerCoroutineId = SwooleCoroutine::getCid();
                $metadata = $context[Collector::LOG_METADATA_KEY];
                $writerRequestId = $metadata instanceof LogMetadata ? $metadata->origin->requestId : null;
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config([
            'trace_log' => ['write_mode' => 'async'],
        ])), $requestContext, $processor);
        $callerCoroutineId = null;

        \Swoole\Coroutine\run(function () use ($requestContext, $logger, &$callerCoroutineId): void {
            $requestContext->start('async-trace');
            $callerCoroutineId = SwooleCoroutine::getCid();
            $logger->info(Collector::Api, []);
            $logger->drain();
        });

        self::assertNotSame($callerCoroutineId, $writerCoroutineId);
        self::assertSame('async-trace', $writerRequestId);
    }

    public function testSyncModeWritesInTheCallingCoroutine(): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $writerCoroutineId = null;
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->willReturnCallback(
            static function () use (&$writerCoroutineId): void {
                $writerCoroutineId = SwooleCoroutine::getCid();
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $config = new LogConfig(new Config(['trace_log' => ['write_mode' => 'sync']]));
        $logger = new CollectorLogger($factory, $config, new RequestContext(), $processor);
        $callerCoroutineId = null;

        \Swoole\Coroutine\run(function () use ($logger, &$callerCoroutineId): void {
            $callerCoroutineId = SwooleCoroutine::getCid();
            $logger->info(Collector::Api, []);
        });

        self::assertSame($callerCoroutineId, $writerCoroutineId);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAsyncDispatchFailureFallsBackToTheCallingCoroutine(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);
        $diagnosticFile = tempnam(sys_get_temp_dir(), 'hyperf-log-dispatch-');
        self::assertIsString($diagnosticFile);
        ini_set('error_log', $diagnosticFile);
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $writerCoroutineId = null;
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->willReturnCallback(
            static function () use (&$writerCoroutineId): void {
                $writerCoroutineId = SwooleCoroutine::getCid();
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config([
            'trace_log' => ['write_mode' => 'async'],
        ])), new RequestContext(), $processor);
        $callerCoroutineId = null;
        set_error_handler(static fn(): bool => true);

        try {
            \Swoole\Coroutine\run(function () use ($logger, &$callerCoroutineId): void {
                $callerCoroutineId = SwooleCoroutine::getCid();
                $logger->info(Collector::Api, []);
                $logger->drain();
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame($callerCoroutineId, $writerCoroutineId);
        self::assertStringContainsString('falling back to sync', (string) file_get_contents($diagnosticFile));
        unlink($diagnosticFile);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function writeModes(): iterable
    {
        yield 'async' => ['async', false];
        yield 'sync' => ['sync', true];
    }

    #[DataProvider('writeModes')]
    public function testWriteCompletionTiming(string $mode, bool $completedOnReturn): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $completed = false;
        $finished = new Channel(1);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->willReturnCallback(
            static function () use (&$completed, $finished): void {
                SwooleCoroutine::sleep(0.001);
                $completed = true;
                $finished->push(true);
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $config = new LogConfig(new Config(['trace_log' => ['write_mode' => $mode]]));
        $logger = new CollectorLogger($factory, $config, new RequestContext(), $processor);
        $observedOnReturn = null;

        \Swoole\Coroutine\run(function () use ($logger, &$completed, &$observedOnReturn, $finished): void {
            $logger->info(Collector::Api, []);
            $observedOnReturn = $completed;
            self::assertTrue($finished->pop(1));
            $logger->drain();
        });

        self::assertSame($completedOnReturn, $observedOnReturn);
    }

    #[DataProvider('writeModes')]
    public function testHandlerFailureNeverEscapesTheCallingCoroutine(string $mode): void
    {
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->willThrowException(new RuntimeException('handler secret'));
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $config = new LogConfig(new Config(['trace_log' => ['write_mode' => $mode]]));
        $logger = new CollectorLogger($factory, $config, new RequestContext(), $processor);
        $businessContinued = false;

        \Swoole\Coroutine\run(function () use ($logger, &$businessContinued): void {
            $logger->info(Collector::Api, []);
            $businessContinued = true;
            $logger->drain();
        });

        self::assertTrue($businessContinued);
    }

    public function testInvalidWriteModeIsNotSwallowedAsAHandlerFailure(): void
    {
        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::never())->method('get');
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $config = new LogConfig(new Config(['trace_log' => ['write_mode' => null]]));
        $this->expectException(\InvalidArgumentException::class);
        new CollectorLogger($factory, $config, new RequestContext(), $processor);
    }

    public function testConcurrentAsyncWritesKeepTheirOwnTraceSnapshot(): void
    {
        $requestContext = new RequestContext();
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturnArgument(1);
        $seen = [];
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::exactly(2))->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$seen): void {
                SwooleCoroutine::sleep(0.001);
                $metadata = $context[Collector::LOG_METADATA_KEY];
                $seen[$context['sequence']] = $metadata instanceof LogMetadata ? $metadata->origin->requestId : null;
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config([
            'trace_log' => ['write_mode' => 'async'],
        ])), $requestContext, $processor);

        \Swoole\Coroutine\run(static function () use ($requestContext, $logger): void {
            $requestContext->start('first');
            $logger->info(Collector::Api, ['sequence' => 1]);
            $requestContext->start('second');
            $logger->info(Collector::Api, ['sequence' => 2]);
            $logger->drain();
        });

        self::assertSame('first', $seen[1]);
        self::assertSame('second', $seen[2]);
    }
}

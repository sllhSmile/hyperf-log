<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Config\Config;
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
use Sllhsmile\HyperfLog\Tests\Fixtures\FailedCoroutine;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Channel;

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

    public function testAsyncModeForksAndCopiesRequestContext(): void
    {
        $requestContext = new RequestContext();
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $writerCoroutineId = null;
        $writerRequestId = null;
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info')->willReturnCallback(
            function () use ($requestContext, &$writerCoroutineId, &$writerRequestId): void {
                $writerCoroutineId = SwooleCoroutine::getCid();
                $writerRequestId = $requestContext->id();
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config([])), $requestContext, $processor);
        $callerCoroutineId = null;

        \Swoole\Coroutine\run(function () use ($requestContext, $logger, &$callerCoroutineId): void {
            $requestContext->start('async-trace');
            $callerCoroutineId = SwooleCoroutine::getCid();
            $logger->info(Collector::Api, []);
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
        $logger = new CollectorLogger($factory, new LogConfig(new Config([])), new RequestContext(), $processor);
        $callerCoroutineId = null;
        set_error_handler(static fn(): bool => true);

        try {
            \Swoole\Coroutine\run(function () use ($logger, &$callerCoroutineId): void {
                $callerCoroutineId = SwooleCoroutine::getCid();
                $logger->info(Collector::Api, []);
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame($callerCoroutineId, $writerCoroutineId);
        self::assertStringContainsString('falling back to sync', (string) file_get_contents($diagnosticFile));
        unlink($diagnosticFile);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNegativeForkResultFallsBackToSynchronousWrite(): void
    {
        require_once __DIR__ . '/Fixtures/FailedCoroutine.php';
        class_alias(FailedCoroutine::class, \Hyperf\Coroutine\Coroutine::class);
        $diagnosticFile = tempnam(sys_get_temp_dir(), 'hyperf-log-negative-fork-');
        self::assertIsString($diagnosticFile);
        ini_set('error_log', $diagnosticFile);
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info');
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config([])), new RequestContext(), $processor);

        $logger->info(Collector::Api, []);

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
        });

        self::assertTrue($businessContinued);
    }

    public function testInvalidWriteModeIsNotSwallowedAsAHandlerFailure(): void
    {
        $factory = $this->createMock(LoggerFactory::class);
        $factory->expects(self::never())->method('get');
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $config = new LogConfig(new Config(['trace_log' => ['write_mode' => null]]));
        $logger = new CollectorLogger($factory, $config, new RequestContext(), $processor);

        $this->expectException(\InvalidArgumentException::class);
        $logger->info(Collector::Api, []);
    }

    public function testConcurrentAsyncWritesKeepTheirOwnTraceSnapshot(): void
    {
        $requestContext = new RequestContext();
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturnArgument(1);
        $seen = [];
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::exactly(2))->method('info')->willReturnCallback(
            static function (string $message, array $context) use ($requestContext, &$seen): void {
                SwooleCoroutine::sleep(0.001);
                $seen[$context['sequence']] = $requestContext->id();
            },
        );
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $logger = new CollectorLogger($factory, new LogConfig(new Config([])), $requestContext, $processor);

        \Swoole\Coroutine\run(static function () use ($requestContext, $logger): void {
            $requestContext->start('first');
            $logger->info(Collector::Api, ['sequence' => 1]);
            $requestContext->start('second');
            $logger->info(Collector::Api, ['sequence' => 2]);
        });

        self::assertSame('first', $seen[1]);
        self::assertSame('second', $seen[2]);
    }
}

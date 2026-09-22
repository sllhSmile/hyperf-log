<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use Hyperf\Command\Command;
use Hyperf\Command\Event\AfterExecute;
use Hyperf\Config\Config;
use Hyperf\Framework\Event\OnWorkerExit;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Server\Event\AllCoroutineServersClosed;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Listener\DispatcherLifecycleListener;
use Sllhsmile\HyperfLog\Support\CollectorLogger;
use Sllhsmile\HyperfLog\Support\LogConfig;

final class DispatcherLifecycleListenerTest extends TestCase
{
    public function testItListensToHyperfWorkerAndCliExitEvents(): void
    {
        $listener = new DispatcherLifecycleListener($this->createMock(CollectorLoggerInterface::class));

        self::assertSame([OnWorkerExit::class, AllCoroutineServersClosed::class, AfterExecute::class], $listener->listen());
    }

    public function testAfterExecuteDrainsQueuedLogs(): void
    {
        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('info');
        $factory = $this->createMock(LoggerFactory::class);
        $factory->method('get')->willReturn($psrLogger);
        $processor = $this->createMock(PayloadProcessorInterface::class);
        $processor->method('process')->willReturn([]);
        $logger = new CollectorLogger(
            $factory,
            new LogConfig(new Config(['trace_log' => ['write_mode' => 'async']])),
            new RequestContext(),
            $processor,
        );
        $listener = new DispatcherLifecycleListener($logger);

        \Swoole\Coroutine\run(function () use ($logger, $listener): void {
            $logger->log(Level::Info, Collector::Api, []);
            $listener->process(new AfterExecute($this->createMock(Command::class)));
        });
    }
}

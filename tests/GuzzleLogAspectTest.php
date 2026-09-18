<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Sllhsmile\HyperfLog\Aspect\GuzzleLogAspect;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Contract\CollectorLoggerInterface;
use Sllhsmile\HyperfLog\Support\GuzzleMiddlewareInstaller;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadSnapshotter;
use Sllhsmile\HyperfLog\Support\SdkLogContextBuilder;

final class GuzzleLogAspectTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(RequestContext::CONTEXT_KEY);
    }

    public function testItProcessesConstructionBeforeInstallingMiddleware(): void
    {
        $seenRequestId = null;
        $stack = HandlerStack::create(static function (RequestInterface $request) use (&$seenRequestId) {
            $seenRequestId = $request->getHeaderLine('x-b3-traceid');

            return Create::promiseFor(new Response());
        });
        $client = new Client(['handler' => $stack]);
        $context = new RequestContext();
        $context->start('aspect-trace');
        $config = new LogConfig(new Config([]));
        $logger = $this->createMock(CollectorLoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $installer = new GuzzleMiddlewareInstaller(
            $config,
            $context,
            new SdkLogContextBuilder($config, new PayloadSnapshotter($config)),
            $logger,
        );
        $original = (fn(): null => null)->bindTo($client, Client::class);
        self::assertNotNull($original);
        $joinPoint = new ProceedingJoinPoint($original, Client::class, '__construct', []);
        $joinPoint->pipe = static fn(): string => 'constructor-result';

        $result = (new GuzzleLogAspect($installer))->process($joinPoint);
        $client->get('https://example.test');

        self::assertSame('constructor-result', $result);
        self::assertSame('aspect-trace', $seenRequestId);
    }
}

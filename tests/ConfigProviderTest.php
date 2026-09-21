<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\ConfigProvider;
use Sllhsmile\HyperfLog\Listener\DispatcherLifecycleListener;
use Sllhsmile\HyperfLog\Middleware\LogMiddleware;

final class ConfigProviderTest extends TestCase
{
    public function testGlobalMiddlewareUsesTheDispatcherCompatibleIndexedShape(): void
    {
        $provider = (new ConfigProvider())();

        self::assertSame([LogMiddleware::class], $provider['middlewares']['http']);
        self::assertContains(DispatcherLifecycleListener::class, $provider['listeners']);
    }
}

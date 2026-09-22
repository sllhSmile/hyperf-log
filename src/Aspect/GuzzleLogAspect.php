<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Aspect;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Sllhsmile\HyperfLog\Support\GuzzleMiddlewareInstaller;

/**
 * 在 Guzzle Client 完成构造后安装链路透传和 SDK 日志 middleware。
 *
 * 安装发生在原构造函数之后，确保读取的是 Guzzle 最终 HandlerStack；非标准 handler
 * 不会被替换。Installer 使用固定名称先移除再添加，避免同一 stack 重复安装。
 */
final class GuzzleLogAspect extends AbstractAspect
{
    /** @var list<string> */
    public array $classes = [Client::class . '::__construct'];

    /** 将 middleware 安装逻辑委托给可重复调用的 installer。 */
    public function __construct(private readonly GuzzleMiddlewareInstaller $installer) {}

    /** 等客户端构造完毕后安装 middleware，避免改动非 HandlerStack handler。 */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $result = $proceedingJoinPoint->process();
        $client = $proceedingJoinPoint->getInstance();
        if ($client instanceof Client) {
            $stack = $client->getConfig('handler');
            if ($stack instanceof HandlerStack) {
                $this->installer->install($stack);
            }
        }

        return $result;
    }
}

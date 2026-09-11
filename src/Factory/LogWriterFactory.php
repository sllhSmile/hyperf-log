<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Factory;

use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogWriter;
use Sllhsmile\HyperfLog\Support\RequestContext;

/**
 * 通过 Hyperf 容器组装 LogWriter。
 *
 * PayloadProcessorInterface 从容器解析，使宿主应用可以替换内容保护策略；同时
 * LogWriter 自身仍保留三参数构造兼容，避免已有测试或手工实例化代码被破坏。
 */
class LogWriterFactory
{
    /**
     * @param ContainerInterface $container Hyperf DI 容器
     */
    public function __invoke(ContainerInterface $container): LogWriter
    {
        return new LogWriter(
            $container->get(LoggerFactory::class),
            $container->get(LogConfig::class),
            $container->get(RequestContext::class),
            $container->get(PayloadProcessorInterface::class),
        );
    }
}

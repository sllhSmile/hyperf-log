<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Enum;

/** 采集器身份，以及其稳定的日志 type 和默认 Monolog channel 映射。 */
enum Collector: string
{
    /**
     * Formatter 的内部识别标记；值必须是 Collector 枚举，且输出 JSON 前必须移除。
     * 不能根据 message 判断采集器，否则同名业务日志会被错误展平。
     */
    public const LOG_CONTEXT_KEY = '__hyperf_log_collector';

    case Api = 'api';
    case Sdk = 'sdk';
    case Database = 'database';
    case Redis = 'redis';

    public function type(): string
    {
        return match ($this) {
            self::Api => 'http.server',
            self::Sdk => 'http.client',
            self::Database => 'database.query',
            self::Redis => 'redis.command',
        };
    }

    public function defaultChannel(): string
    {
        return match ($this) {
            self::Api => 'apilog',
            self::Sdk => 'sdklog',
            self::Database => 'dblog',
            self::Redis => 'redislog',
        };
    }
}

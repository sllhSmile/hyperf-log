<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Enum;

/** 采集器身份，以及其稳定的日志 type 和默认 Monolog channel 映射。 */
enum Collector: string
{
    /** 采集器身份和提交时快照，只供 Formatter 使用且不得输出到业务 context。 */
    public const LOG_METADATA_KEY = '__hyperf_log_metadata';

    case Api = 'api';
    case Sdk = 'sdk';
    case Database = 'database';
    case Redis = 'redis';

    /** 返回记录中的固定事件类型，与物理日志 channel 独立。 */
    public function type(): string
    {
        return match ($this) {
            self::Api => 'http.server',
            self::Sdk => 'http.client',
            self::Database => 'database.query',
            self::Redis => 'redis.command',
        };
    }

    /** 返回 Monolog logger name；实际 Handler 由 logger_channel 指定。 */
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

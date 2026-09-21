<?php

declare(strict_types=1);

use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\WriteMode;

return [
    // HTTP 入站请求、响应与 Guzzle 出站请求共用的 request-id Header 名称。
    'request_id_header' => 'x-b3-traceid',
    // null 跟随 logger.default；也可填写 logger.channels 中已有的 channel 名称。
    'logger_channel' => null,
    // sync 是可靠基线；async 在协程环境使用每 Worker 一个有界队列和常驻消费协程。
    'write_mode' => WriteMode::SYNC,
    'async' => [
        // 队列内容的估算字节预算，不代表 PHP Worker 的 RSS 硬限制。
        'max_buffer_bytes' => LogConfig::DEFAULT_ASYNC_MAX_BUFFER_BYTES,
    ],
    // 采集器默认关闭；API/SDK 关闭响应详情仍记录状态码，其余响应/结果不采集。
    'collectors' => [
        'api' => ['enabled' => false, 'response_enabled' => true],
        'sdk' => ['enabled' => false, 'response_enabled' => false],
        'database' => ['enabled' => false, 'response_enabled' => false],
        'redis' => ['enabled' => false, 'response_enabled' => false],
    ],
    'payload' => [
        // Header、URL query、JSON 和表单字段均按名称递归匹配，不区分大小写。
        'sensitive_fields' => LogConfig::DEFAULT_SENSITIVE_FIELDS,
        'redaction_value' => '****',
        // 单个大字段的字节上限；字符串截断、结构化值省略，null 表示不限制。
        'max_bytes' => 64 * 1024,
    ],
];

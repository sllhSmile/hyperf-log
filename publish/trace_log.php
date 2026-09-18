<?php

declare(strict_types=1);

return [
    // HTTP 入站请求、响应与 Guzzle 出站请求共用的 request-id Header 名称。
    'request_id_header' => 'x-b3-traceid',
    // null 跟随 logger.default；也可填写 logger.channels 中已有的 channel 名称。
    'logger_channel' => null,
    // async 在协程中派生子协程写入；sync 在当前执行单元完成写入尝试。
    'write_mode' => 'async',
    // 采集器默认关闭；response_enabled=false 时不会读取或输出对应响应/结果。
    'collectors' => [
        'api' => ['enabled' => false, 'response_enabled' => true],
        'sdk' => ['enabled' => false, 'response_enabled' => false],
        'database' => ['enabled' => false, 'response_enabled' => false],
        'redis' => ['enabled' => false, 'response_enabled' => false],
    ],
    'payload' => [
        // Header、URL query、JSON 和表单字段均按名称递归匹配，不区分大小写。
        'sensitive_fields' => [
            'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key',
            'password', 'passwd', 'token', 'access_token', 'refresh_token', 'api_key',
            'api-key', 'secret', 'client_secret',
        ],
        'redaction_value' => '****',
        // 单个大字段的字节上限；字符串截断、结构化值省略，null 表示不限制。
        'max_bytes' => 64 * 1024,
    ],
];

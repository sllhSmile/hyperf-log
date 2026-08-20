<?php

declare(strict_types=1);

/**
 * Trace Log 公共包的共享请求链路和 Guzzle 配置。
 *
 * 发布后文件位于 config/autoload/trace_log.php，对应的配置根为 trace_log。
 * redislog、sdklog、apilog、dblog 的 handler、formatter 及 enabled 开关仍应由
 * 宿主应用直接写入 config/autoload/logger.php 的 channels 内。
 *
 * HTTP 与命令行的 trace 上下文由公共包自动初始化。RPC 组件的 middleware 接口因
 * json-rpc、grpc 等实现不同而不同，请在具体 RPC middleware 的最前面调用
 * RequestContext::initializeTrace()；该方法不依赖 HTTP 请求对象。
 *
 * dblog.response_enabled 和 sdklog.response_enabled 位于宿主 logger.php 的同名
 * channel 内，默认建议 false；开启后分别记录数据库 result 和 SDK 响应体。
 */
return [
    // 入站请求与 Guzzle 出站请求共用的 request-id Header 名称。
    'request_id_header' => 'x-b3-traceid',
    // request-id 在当前协程上下文中的存储键。
    'request_id_context_key' => 'x-b3-traceid',
    // Guzzle 出站请求开始时间 Header 名称，用于计算 SDK 调用耗时。
    'request_start_header' => 'x-request-start-time',
    // HTTP 入站请求开始时间在当前协程上下文中的存储键。
    'request_start_context_key' => 'request_start_time',
    // Guzzle 客户端的统一行为配置；安装公共包后始终生效。
    'guzzle' => [
        // 单次 HTTP 请求的默认总超时时间，单位：秒。
        'timeout' => 10,
        // 建立 TCP 连接的默认超时时间，单位：秒。
        'connect_timeout' => 10,
    ],
];

# Hyperf Log

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Hyperf](https://img.shields.io/badge/Hyperf-%5E3.2-0E8A16)](https://hyperf.io/)
[![License](https://img.shields.io/badge/license-MIT-22C55E)](LICENSE)

面向 **Hyperf 3** 的结构化日志与请求链路追踪包。它统一采集 HTTP API、数据库、Redis 和 Guzzle 调用日志，并在同一协程链路中复用 request ID。

## 特性

| 能力 | 说明 |
| --- | --- |
| API 日志 | 记录 HTTP 请求与响应生命周期数据。 |
| 数据库日志 | 记录 SQL、耗时和执行结果摘要。 |
| Redis 日志 | 记录 Redis 命令及其执行信息。 |
| Guzzle 日志 | 自动注入链路 Header、默认超时，并记录 SDK 调用。 |
| 协程链路追踪 | HTTP、CLI 与手动初始化的 RPC/后台协程共享 request ID。 |
| 按 channel 开关 | 每类采集器可独立启用，避免安装后产生额外日志。 |

## 要求

- PHP `>= 8.2`
- Hyperf `^3.2`

## 安装

### Packagist

Packagist 注册完成后：

```bash
composer require sllhsmile/hyperf-log:^0.4
```

### GitHub 仓库

尚未注册 Packagist 时，在宿主项目的 `composer.json` 中加入：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sllhSmile/hyperf-log.git"
        }
    ]
}
```

然后安装：

```bash
composer require sllhsmile/hyperf-log:^0.4
```

发布链路配置：

```bash
php bin/hyperf.php vendor:publish sllhsmile/hyperf-log --id=trace-log-config
```

> Hyperf 会通过 `ConfigProvider` 自动注册本包的 Listener、Aspect 和 HTTP Middleware。

## 快速配置

本包不会覆盖宿主项目的 `config/autoload/logger.php`。在 `logger.channels` 中添加所需 channel；下例复用 `default` 的 handler 与 formatter，因此所有结构化日志均写入同一个 `file.log`：

```php
use Sllhsmile\HyperfLog\Formatter\CustomizeJsonFormatter;

'default' => [
    'handler' => [
        // 按宿主项目需要配置 handler。
    ],
    'formatter' => [
        'class' => CustomizeJsonFormatter::class,
    ],
],
'redislog' => [
    'enabled' => true,
    'handlers' => ['default'],
],
'apilog' => [
    'enabled' => true,
    'handlers' => ['default'],
],
'sdklog' => [
    'enabled' => true,
    'response_enabled' => false,
    'handlers' => ['default'],
],
'dblog' => [
    'enabled' => true,
    'response_enabled' => false,
    'handlers' => ['default'],
],
```

`CustomizeJsonFormatter` 输出单行 JSON。采集器日志会将 `sdklog`、`dblog` 等记录类型写入
`message_type`，并将其 context 平铺到顶层；普通日志（没有 context）使用
`{app_name}_log` 作为 `message_type`，原文保留在 `message`。`datetime`、`message_type`、
`request_id`、`coroutine_id` 是保留字段，不会被 context 覆盖。

宿主应用可按自身部署方式设置默认文件 handler：

```php
'filename' => env('APP_ENV') === 'local'
    ? BASE_PATH . '/runtime/logs/file.log'
    : env('HY_LOG_PATH') . '/file.log',
```

| Channel | 采集内容 | 默认建议 |
| --- | --- | --- |
| `apilog` | HTTP API 请求与响应 | 按需启用 |
| `dblog` | 数据库查询 | `response_enabled=false` |
| `redislog` | Redis 命令 | 按需启用 |
| `sdklog` | Guzzle 请求与响应 | `response_enabled=false` |

## 链路追踪

发布后的 `config/autoload/trace_log.php` 控制 request ID 与 Guzzle 默认行为：

```php
return [
    'request_id_header' => 'x-b3-traceid',
    'request_id_context_key' => 'x-b3-traceid',
    'request_start_header' => 'x-request-start-time',
    'request_start_context_key' => 'x-request-start-time',
    'guzzle' => [
        // 可选：未声明时使用 Guzzle 默认行为。
        // 'timeout' => 10,
        // 'connect_timeout' => 10,
    ],
];
```

HTTP 请求和 CLI 命令会自动建立 trace：入站 `request_id` 缺失时生成 UUID v7，写入请求、
协程 Context 和 HTTP 响应 Header；Guzzle 与异步日志子协程会继承同一个 ID。宿主项目可继续
使用自有的 `getRequestId()` Helper，但该全局函数不是本包强制提供的。

- 有效的上游 `x-b3-traceid` 会原样透传。
- Header 缺失或为空时，会生成 UUID v7，并写入协程 Context 与后续请求对象。
- Guzzle 自动带上 request ID 与开始时间；公共包仅为 Hyperf 协程 Handler 回写 Swoole 超时配置，优先级为调用方 `swoole.*`（如有）、调用方顶层 `timeout` / `connect_timeout`、`trace_log.guzzle` 的显式配置、Swoole 默认行为；不会修改普通 cURL Guzzle Handler 使用的顶层超时参数。
- CLI 会通过 `BeforeHandle` 初始化链路。RPC 或其他后台协程请在入口注入 `RequestContext` 并调用 `initializeTrace()`。

获取当前 request-id：在业务类中注入 `Sllhsmile\HyperfLog\Support\RequestContext`，调用
`$requestContext->id()`；HTTP 请求也可以通过 `$request->getHeaderLine('x-b3-traceid')`
读取客户端传入的值。未传入 Header 时，请使用 `RequestContext::id()` 获取自动生成的最终值。

## 注意事项

- API 日志依赖 HTTP server 的 `enable_request_lifecycle=true`。
- 请求/响应 body、Header、SQL bindings 和 Redis 参数可能含敏感信息；生产环境应在 formatter 或 processor 中脱敏。
- 已有同类 Listener、Middleware 或 Guzzle Aspect 时，请关闭其中一套，避免重复日志和重复 Header 注入。
- 使用 `handlers => ['default']` 时，四类日志会写入同一文件；如需分文件，请为每个 channel 配置独立 handler。

## License

MIT. See [LICENSE](LICENSE).

# Hyperf Log

[![Latest Stable Version](https://img.shields.io/packagist/v/sllhsmile/hyperf-log.svg)](https://packagist.org/packages/sllhsmile/hyperf-log)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Hyperf](https://img.shields.io/badge/Hyperf-%5E3.2-0E8A16)](https://hyperf.io/)
[![License](https://img.shields.io/badge/license-MIT-22C55E)](LICENSE)

为 Hyperf 应用统一记录 HTTP API、数据库、Redis 和 Guzzle 日志，并用同一个 request ID 串联请求、业务日志与下游调用。

本包不是完整的分布式追踪系统，不生成 span 或 metrics；异步采集日志也不适合作为零丢失的审计日志。

## 兼容性

| hyperf-log | PHP | Hyperf | Guzzle |
| --- | --- | --- | --- |
| `^0.7` | `>=8.2` | `^3.2` | `^7.0` |

## 安装

```bash
composer require sllhsmile/hyperf-log:^0.7
php bin/hyperf.php vendor:publish sllhsmile/hyperf-log --id=trace-log-config
```

Hyperf 会通过 Composer 自动发现 `Sllhsmile\HyperfLog\ConfigProvider`。配置发布到 `config/autoload/trace_log.php`。

## 快速开始

`apilog` 依赖 HTTP server 的请求生命周期事件：

```php
'options' => [
    'enable_request_lifecycle' => true,
],
```

在 `trace_log.php` 中启用需要的采集器：

```php
'logger_channel' => null, // 跟随 logger.default；也可指定已有的 daily、stderr 等 channel

'collectors' => [
    'api' => ['enabled' => true, 'response_enabled' => true],
    'sdk' => ['enabled' => false, 'response_enabled' => false],
    'database' => ['enabled' => false, 'response_enabled' => false],
    'redis' => ['enabled' => false, 'response_enabled' => false],
],
```

`logger_channel` 只引用 `config/autoload/logger.php` 中已经存在的 channel，不重复定义 Handler 或 Formatter。省略或设为 `null` 时使用 `logger.default`。如果所选 channel 已使用 `StructuredJsonFormatter`，无需再修改 `logger.php`；否则可按下面方式为该 channel 配置结构化输出：

```php
<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Sllhsmile\HyperfLog\Formatter\StructuredJsonFormatter;

return [
    'default' => 'default',
    'channels' => [
        'default' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => BASE_PATH . '/runtime/logs/trace.log',
                    'level' => Logger::INFO,
                ],
            ],
            'formatter' => ['class' => StructuredJsonFormatter::class],
        ],
    ],
];
```

四类采集器共用所选的物理 channel，但仍以 `apilog`、`sdklog`、`dblog`、`redislog` 作为 Monolog logger name，因此 JSON 中的 `channel` 字段保持可区分。

重启 Worker，访问任意路由：

```bash
curl -i -H 'x-b3-traceid: demo-trace-001' http://127.0.0.1:9501/
tail -n 1 runtime/logs/trace.log
```

响应和日志应包含 `demo-trace-001`。

## 日志结构

采集器分别使用 `http.server`、`http.client`、`database.query` 和 `redis.command` 类型。普通应用日志使用 `application`，原始消息和上下文分别位于 `message`、`context`，业务上下文无法覆盖保留字段。

```json
{
  "schema_version": 1,
  "timestamp": "2026-09-16 10:20:30.123456+08:00",
  "level": "INFO",
  "type": "http.server",
  "channel": "apilog",
  "service": "xthk",
  "request_id": "demo-trace-001",
  "coroutine_id": 12,
  "duration_ms": 12.35,
  "request": {"method": "POST", "url": "/users", "headers": {}, "body": {"name": "smile"}},
  "response": {"status_code": 200, "headers": {}, "body": {"code": 0}}
}
```

`timestamp` 固定使用北京时间（`Asia/Shanghai`），格式为 `Y-m-d H:i:s.uP`。Header 名称统一转为小写，Header 值保持数组。无响应、无错误等不适用字段直接省略；`response_enabled=false` 时完全不读取或输出 `response`。

## Request context

HTTP 和 CLI 入口会自动创建 trace。RPC、队列消费者等独立执行单元应在入口显式调用 `start()`：

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Sllhsmile\HyperfLog\Context\RequestContext;

final readonly class CurrentTrace
{
    public function __construct(private RequestContext $context) {}

    public function begin(?string $upstreamId = null): void
    {
        $this->context->start($upstreamId);
    }

    public function id(): ?string
    {
        return $this->context->id();
    }

    public function startedAt(): ?float
    {
        return $this->context->startTime();
    }
}
```

`start()` 是唯一生成入口；`current()`、`id()`、`startTime()` 都是无副作用查询。内部 Context key 固定为 `RequestContext::class`，无需配置。

## 内容保护

`trace_log.payload` 提供字段脱敏、替换文本和单字段字节上限：

```php
'payload' => [
    'sensitive_fields' => ['authorization', 'cookie', 'password', 'token'],
    'redaction_value' => '****',
    'max_bytes' => 64 * 1024,
],
```

- API 和 SDK 的 Header、URL query、JSON、表单及结构化字段会递归脱敏。
- 显式 JSON 无法解析时采用 fail-closed：正文被省略。
- Redis `AUTH` 始终遮蔽；其他 Redis 命令和 SQL 不做字段语义猜测。
- 可回绕流读取后恢复位置；Hyperf `SwooleStream` 可安全快照；其他不可回绕流不会被消费。
- 文本超限时截断，结构化数据或流超限时省略。

保护动作统一写入 `payload_protection`：

```json
{
  "payload_protection": [
    {"path": "response.body", "action": "omitted", "reason": "limit_exceeded", "limit_bytes": 65536}
  ]
}
```

动作包括 `truncated`、`omitted`；原因包括 `invalid_json`、`limit_exceeded`、`non_rewindable_stream`、`read_failed`。

## Guzzle

Guzzle 客户端会自动透传当前 request ID，并在启用 SDK 采集器时记录调用日志。本包不设置或改写 `timeout`、`connect_timeout` 及 `swoole` 选项；连接和请求超时应由宿主应用或单次请求自行管理。

## 从 0.6 升级

0.7 是破坏性版本：

- `RequestContext` 移至 `Sllhsmile\HyperfLog\Context`，`TraceContextData` 更名为 `TraceContext`。
- `CustomizeJsonFormatter` 更名为 `StructuredJsonFormatter`。
- 采集器开关与响应配置移至 `trace_log.collectors`；`trace_log.logger_channel` 统一选择已有的 Hyperf Logger channel。
- 移除可配置的 Context key 和内部 Guzzle 开始时间 Header。
- 日志升级为 `schema_version: 1`，使用固定北京时间的微秒时间、`type`、`error` 和 `payload_protection`。
- 扩展 `PayloadProcessorInterface` 的实现需将 `process(string, array)` 改为 `process(Collector, array)`。

## 开发验证

```bash
composer test
composer analyse
composer cs
composer check
```

MIT License。

# Hyperf Log

[![Latest Stable Version](https://img.shields.io/packagist/v/sllhsmile/hyperf-log.svg)](https://packagist.org/packages/sllhsmile/hyperf-log)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Hyperf](https://img.shields.io/badge/Hyperf-%5E3.2-0E8A16)](https://hyperf.io/)
[![License](https://img.shields.io/badge/license-MIT-22C55E)](LICENSE)

为 Hyperf 应用统一记录 HTTP API、数据库、Redis 和 Guzzle 日志，并用同一个 request ID 串联请求、业务日志与下游调用。

本包不是完整的分布式追踪系统，不生成 span 或 metrics；异步采集日志也不适合作为零丢失的审计日志。

## 兼容性

| hyperf-log | PHP | Hyperf | Swoole | Guzzle |
| --- | --- | --- | --- | --- |
| `>=0.8 <1.0` | `>=8.2` | `^3.2` | 官方 Swoole `>=5.0` | `^7.0` |

从 0.7.1 起，Composer 显式要求 `ext-swoole >=5.0`；普通 PHP 和 OpenSwoole 不在运行支持范围。PHP 依赖约束允许 `>=8.2`，CI 配置覆盖 8.2、8.3、8.4 并在每个任务中安装 Swoole，不代表已验证全部 PHP/Swoole 版本组合。CLI 的非协程执行路径也需要安装 Swoole 扩展。

## 安装

```bash
composer require sllhsmile/hyperf-log:^0.8
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
'write_mode' => \Sllhsmile\HyperfLog\Support\WriteMode::SYNC, // 默认可靠基线；async 使用每 Worker 有界队列
'async' => [
    'max_buffer_bytes' => 8 * 1024 * 1024,
],

'collectors' => [
    'api' => ['enabled' => true, 'response_enabled' => true],
    'sdk' => ['enabled' => false, 'response_enabled' => false],
    'database' => ['enabled' => false, 'response_enabled' => false],
    'redis' => ['enabled' => false, 'response_enabled' => false],
],
```

`collectors.*.enabled` 和 `response_enabled` 只接受布尔值，字符串 `"true"`、`"false"` 等配置会直接抛出 `InvalidArgumentException`，避免意外开启敏感日志。`request_id_header` 必须是非空且合法的 HTTP Header 名称。

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

`timestamp` 固定使用北京时间（`Asia/Shanghai`），格式为 `Y-m-d H:i:s.uP`。Header 名称统一转为小写，Header 值保持数组。无响应、无错误等不适用字段直接省略；API/SDK 的 `response_enabled=false` 时仍输出 `response.status_code`，但不读取或输出响应 Header、Body。数据库和 Redis 的 `response_enabled` 行为不变。

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

本包在提交日志时快照 request ID、事件时间和源协程 ID，不依赖消费协程读取请求 Context。SDK 请求还会在发起请求时保存来源快照，因此 Promise 即使在其他协程完成，日志仍使用原请求的 ID。宿主自行创建业务子协程时，仍应显式使用 `Hyperf\Coroutine\Coroutine::fork($callback, [RequestContext::CONTEXT_KEY])` 继承链路；本包不会改写宿主的协程创建逻辑。

## 采集日志写入模式

`trace_log.write_mode` 支持严格值 `async` 和 `sync`（可使用 `WriteMode::ASYNC` 和 `WriteMode::SYNC` 常量）；从 0.8 起省略时为 `sync`，大小写、空白或其他类型均不接受，构造日志服务时抛出 `InvalidArgumentException`。

- `sync`：在当前执行单元中完成内容保护和 Handler 写入尝试后返回，是默认可靠基线；可能增加业务延迟。
- `async`：每个 Worker 惰性创建一个有界队列和一个消费协程，不会为每条日志创建协程。队列按估算字节受 `async.max_buffer_bytes` 限制，默认 8 MiB；满载时短暂等待 1ms，随后同步回退，不静默丢弃。
- 两种模式都隔离内容处理和 Handler 异常，并输出仅包含异常类型和 request ID 的内部诊断，不改变业务结果。非法配置不属于 Handler 异常，不会静默降级。

该开关只控制四类采集日志，不改动宿主直接写入的普通业务日志。Hyperf 的 `OnWorkerExit`、`AllCoroutineServersClosed` 和 CLI `AfterExecute` 会触发最多 3 秒的 drain；部署时 `server.settings.max_wait_time` 不应小于 3 秒。正常退出仍是 best-effort，SIGKILL、OOM、进程崩溃和 Handler 自身故障可能丢日志；字节预算也是队列内容估算值，不是 Worker RSS 上限。同步模式同样不承诺物理落盘，审计日志应使用持久队列或外部 Collector。可复现测法及本机基线见 [性能验证](https://github.com/sllhSmile/hyperf-log/blob/main/benchmark/README.md)。

采集日志会根据结果选择级别：HTTP API/SDK 的 4xx 为 `WARNING`，5xx 和异常为 `ERROR`，其他结果为 `INFO`；Database 仅在 `QueryExecuted::result` 为 `Throwable` 时使用 `ERROR`，Redis 异常使用 `ERROR`。API/SDK 即使关闭响应详情，也会按响应状态码判断级别；没有响应且无异常时使用 `INFO`。级别只影响 Monolog 记录及 Handler 阈值，不代表自动写入不同文件；四类采集器仍共用 `logger_channel` 选择的物理 channel。

`CollectorLoggerInterface` 提供 PSR-3 的八个级别方法。第三个可选 `LogOrigin` 参数用于显式传递操作发起时的 request ID 和协程 ID；自定义实现必须保留该参数，未提供时再读取当前协程 Context。

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

生产环境不要把字段脱敏当作全面的敏感数据保障：SQL 会插入原始 bindings，Redis 非 `AUTH` 命令记录完整参数；异常文本、自由文本正文、URL 路径等也不做敏感值语义识别。四类采集器默认关闭，应按数据分级启用，尤其谨慎开启数据库、Redis 及响应结果采集。本版保留这些输出行为，HTTP/SDK 的保护承诺仅针对配置字段名匹配和上述流/JSON 边界。

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

Guzzle 客户端会自动透传当前 request ID，并在启用 SDK 采集器时记录调用日志。本包不设置或改写 `timeout`、`connect_timeout` 及 `swoole` 选项；连接和请求超时应由宿主应用或单次请求自行管理。数据库日志只依赖 Hyperf 的 `QueryExecuted` 事件，不对数据库连接方法增加 AOP；在事件派发前直接抛出的查询异常不会生成 `database.query` 日志。

自动安装仅针对 `HandlerStack`；自定义裸 handler 不会被替换。Redis 采集依赖 Hyperf Redis 的 `CommandExecuted` 事件，宿主须启用 Redis 事件（通常为 `REDIS_EVENT_ENABLE=true`），只打开本包开关不足以产生 Redis 日志。

## 从 0.7.1 升级

0.8 保持 Schema 1，但默认写入模式由 `async` 改为 `sync`，并移除每日志子协程。需要异步写入时显式设置 `write_mode=async`，可通过 `async.max_buffer_bytes` 调整每 Worker 的队列预算。`CollectorLoggerInterface` 新增七个级别方法及可选 `LogOrigin` 参数；自定义实现必须补齐八级接口并保持完整签名。

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
composer benchmark -- --count=2000 --concurrency=16 --delay-us=1000
```

这些命令面向 GitHub 源码仓库；Composer dist 会排除测试和 benchmark 开发资料。测试包含真实 Swoole 协程，以及包内 API/Guzzle/DB/Redis/CLI 事件与 Handler 集成链路；不启动完整 Hyperf 服务，也不连接真实 MySQL 或 Redis。性能验证不作为 CI 硬阈值。

MIT License。

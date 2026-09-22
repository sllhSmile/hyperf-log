# Hyperf Log

[![Latest Stable Version](https://img.shields.io/packagist/v/sllhsmile/hyperf-log.svg)](https://packagist.org/packages/sllhsmile/hyperf-log)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Hyperf](https://img.shields.io/badge/Hyperf-%5E3.2-0E8A16)](https://hyperf.io/)
[![License](https://img.shields.io/badge/license-MIT-22C55E)](LICENSE)

为 Hyperf 应用统一采集 HTTP API、Guzzle、数据库和 Redis 日志，并使用同一个 request ID 串联入站请求、业务日志和下游调用。

本包专注于结构化日志和请求链路标识，不生成 span、metrics，也不替代需要零丢失保证的审计日志系统。

## 目录

- [采集能力](#采集能力)
- [运行要求](#运行要求)
- [安装](#安装)
- [五分钟接入](#五分钟接入)
- [配置说明](#配置说明)
- [日志结构与级别](#日志结构与级别)
- [Request ID 与协程](#request-id-与协程)
- [同步与异步写入](#同步与异步写入)
- [内容保护](#内容保护)
- [Guzzle、数据库和 Redis](#guzzle数据库和-redis)
- [开发验证](#开发验证)

## 采集能力

| 采集器 | 日志名称 | `type` | 数据来源 |
| --- | --- | --- | --- |
| API | `apilog` | `http.server` | Hyperf HTTP 请求生命周期 |
| SDK | `sdklog` | `http.client` | Guzzle `HandlerStack` middleware |
| Database | `dblog` | `database.query` | Hyperf `QueryExecuted` 事件 |
| Redis | `redislog` | `redis.command` | Hyperf `CommandExecuted` 事件 |

四类采集器默认关闭，可以独立启用。它们共用 `logger_channel` 指向的物理 Monolog channel，但 JSON 中的日志名称和 `type` 始终可区分。

## 运行要求

| hyperf-log | PHP | Hyperf | Swoole | Guzzle |
| --- | --- | --- | --- | --- |
| `^0.8.2` | `>=8.2` | `^3.2` | 官方 Swoole `>=5.0` | `^7.0` |

- Composer 明确要求 `ext-swoole >=5.0`；普通 PHP 环境和 OpenSwoole 不在支持范围。
- 依赖约束允许 PHP `>=8.2`；CI 使用 PHP 8.2、8.3、8.4 分别执行语法检查和完整质量检查。
- CLI 的非协程执行路径也必须安装 Swoole 扩展。
- `hyperf/guzzle` 是可选集成，不再由本包强制安装。使用 `Hyperf\Guzzle\ClientFactory` 的项目应自行安装 `hyperf/guzzle:^3.2`。

## 安装

```bash
composer require sllhsmile/hyperf-log:^0.8.2
php bin/hyperf.php vendor:publish sllhsmile/hyperf-log --id=trace-log-config
```

Hyperf 会通过 Composer 自动发现 `Sllhsmile\HyperfLog\ConfigProvider`。第二条命令会生成 `config/autoload/trace_log.php`。

## 五分钟接入

下面以 API 日志为例完成第一次可观察的采集。

### 1. 开启 HTTP 请求生命周期事件

在 `config/autoload/server.php` 对目标 HTTP server 开启：

```php
'options' => [
    'enable_request_lifecycle' => true,
],
```

`apilog` 依赖该事件；未开启时即使采集器配置为 `enabled=true` 也不会产生 API 日志。

### 2. 配置日志输出 channel

在现有 `config/autoload/logger.php` 中合并一个 channel，不要删除项目已有的 channel：

```php
<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Sllhsmile\HyperfLog\Formatter\StructuredJsonFormatter;

return [
    'default' => 'default',
    'channels' => [
        'trace' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => BASE_PATH . '/runtime/logs/trace.log',
                    'level' => Logger::INFO,
                ],
            ],
            'formatter' => [
                'class' => StructuredJsonFormatter::class,
            ],
        ],
    ],
];
```

如果项目已有适合的 channel，可以直接复用，无需重复定义 Handler。要获得本文展示的单行 JSON 结构，该 channel 应使用 `StructuredJsonFormatter`。

### 3. 启用 API 采集器

修改已发布的 `config/autoload/trace_log.php`：

```php
'logger_channel' => 'trace',
'write_mode' => \Sllhsmile\HyperfLog\Support\WriteMode::SYNC,

'collectors' => [
    'api' => ['enabled' => true, 'response_enabled' => true],
    'sdk' => ['enabled' => false, 'response_enabled' => false],
    'database' => ['enabled' => false, 'response_enabled' => false],
    'redis' => ['enabled' => false, 'response_enabled' => false],
],
```

### 4. 重启并验证

配置在 Worker 启动时读取和校验，修改后必须重启 Hyperf Worker：

```bash
curl -i -H 'x-b3-traceid: demo-trace-001' http://127.0.0.1:9501/
tail -n 1 runtime/logs/trace.log
```

响应 Header 和最新一条日志中都应出现 `demo-trace-001`。未提供 request ID 时，本包会生成 UUID v7 并写回响应 Header。

## 配置说明

完整默认配置见 [`publish/trace_log.php`](publish/trace_log.php)。

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `request_id_header` | `x-b3-traceid` | 入站、响应和 Guzzle 出站请求共用的 Header |
| `logger_channel` | `null` | `null` 跟随 `logger.default`，也可指定已有 channel |
| `write_mode` | `sync` | 仅接受 `sync` 或 `async` |
| `async.max_buffer_bytes` | `8388608` | 每 Worker 异步队列的估算字节预算，最小 1024 |
| `collectors.api.enabled` | `false` | 是否采集 HTTP API 日志 |
| `collectors.api.response_enabled` | `true` | 是否读取 API 响应 Header 和 Body |
| `collectors.sdk.enabled` | `false` | 是否采集 Guzzle 调用日志 |
| `collectors.sdk.response_enabled` | `false` | 是否读取 Guzzle 响应 Header 和 Body |
| `collectors.database.enabled` | `false` | 是否采集已完成的数据库查询 |
| `collectors.database.response_enabled` | `false` | 是否记录查询结果 |
| `collectors.redis.enabled` | `false` | 是否采集 Redis 命令 |
| `collectors.redis.response_enabled` | `false` | 是否记录 Redis 命令结果 |
| `payload.sensitive_fields` | 内置敏感字段列表 | 按字段名递归脱敏，不区分大小写 |
| `payload.redaction_value` | `****` | 敏感字段替换文本 |
| `payload.max_bytes` | `65536` | 单字段字节上限；`null` 表示不限制 |

配置采用严格类型：

- `enabled` 和 `response_enabled` 只接受布尔值，字符串 `"true"`、`"false"` 会抛出 `InvalidArgumentException`。
- `request_id_header` 必须是合法的非空 HTTP Header 名称。
- `logger_channel` 必须是非空字符串或 `null`。
- 所有配置在 `LogConfig` 构造时一次性读取并保存为不可变快照；运行期间修改配置文件不会动态生效。
- 日志中的 `service` 优先读取宿主的 `app_name`，缺失时回退到 `app_env`。

## 日志结构与级别

采集日志使用版本化的单行 JSON envelope：

```json
{
  "schema_version": 1,
  "timestamp": "2026-09-16 10:20:30.123456+08:00",
  "level": "INFO",
  "type": "http.server",
  "channel": "apilog",
  "service": "skeleton",
  "request_id": "demo-trace-001",
  "coroutine_id": 12,
  "duration_ms": 12.35,
  "request": {
    "method": "POST",
    "url": "/users",
    "headers": {},
    "body": {"name": "smile"}
  },
  "response": {
    "status_code": 200,
    "headers": {},
    "body": {"code": 0}
  }
}
```

- `timestamp` 固定为 `Asia/Shanghai`，格式为 `Y-m-d H:i:s.uP`。
- Header 名称统一为小写，Header 值保持数组。
- 不适用的 `response`、`error` 等字段直接省略。
- 普通应用日志使用 `type=application`，原消息和上下文分别位于 `message`、`context`。
- 业务 context 不能覆盖 `schema_version`、`timestamp`、`level` 等保留字段。

级别规则如下：

| 场景 | 级别 |
| --- | --- |
| API/SDK 状态码 400–499 | `WARNING` |
| API/SDK 状态码 500–599，或存在异常 | `ERROR` |
| API/SDK 其他结果 | `INFO` |
| Redis 命令异常 | `ERROR` |
| Database `QueryExecuted::result` 为 `Throwable` | `ERROR` |
| Database/Redis 其他结果 | `INFO` |

API/SDK 即使设置 `response_enabled=false`，仍保留真实 `response.status_code` 并据此判断级别，但不会读取响应 Header 和 Body。日志级别会受到所选 Monolog Handler 阈值影响，并不代表自动写入不同文件。

公开枚举 `Sllhsmile\HyperfLog\Enum\HttpStatusClass` 可以通过 `tryFromStatusCode(int): ?self` 将 100–599 状态码归为五类，范围外返回 `null`。

## Request ID 与协程

HTTP 和 CLI 入口会自动开始一条新 trace。RPC、队列消费者等独立执行单元应在入口显式调用 `RequestContext::start()`：

```php
<?php

declare(strict_types=1);

namespace App\Consumer;

use Sllhsmile\HyperfLog\Context\RequestContext;

final readonly class MessageConsumer
{
    public function __construct(private RequestContext $requestContext) {}

    public function consume(?string $upstreamRequestId): void
    {
        $this->requestContext->start($upstreamRequestId);

        // 执行业务逻辑。
    }
}
```

`start()` 是唯一生成入口；`current()`、`id()` 和 `startTime()` 都是无副作用查询。入站 ID 只会被 trim，不强制要求 UUID 格式。

采集日志在提交时快照 request ID、事件时间和源协程 ID。SDK 日志还会在发起请求时保存来源，因此 Promise 即使在另一协程完成，仍使用原请求的链路信息。

宿主自行创建业务子协程时，应显式继承请求上下文：

```php
Hyperf\Coroutine\Coroutine::fork(
    $callback,
    [Sllhsmile\HyperfLog\Context\RequestContext::CONTEXT_KEY],
);
```

本包不会改写宿主的协程创建逻辑。

## 同步与异步写入

`write_mode` 可以使用 `WriteMode::SYNC` 和 `WriteMode::ASYNC` 常量：

- `sync`：默认模式，在当前执行单元完成内容保护和 Handler 写入尝试后返回，可靠性更直观，但可能增加请求延迟。
- `async`：每个 Worker 首次提交日志时惰性创建一个有界队列和一个消费协程，不会为每条日志创建新协程。内容保护和上下文快照仍在调用协程完成，只有 Handler IO 被异步化。

异步队列按估算字节受 `async.max_buffer_bytes` 限制，默认 8 MiB。队列满载时短暂等待 1ms，随后同步回退，不会静默丢弃。

Hyperf 的 `OnWorkerExit`、`AllCoroutineServersClosed` 和 CLI `AfterExecute` 会触发最多 3 秒的 drain。部署时建议保证 `server.settings.max_wait_time >= 3`。正常退出仍是 best-effort；SIGKILL、OOM、进程崩溃和 Handler 故障都可能导致日志丢失。同步模式也不承诺物理落盘，因此审计日志应使用持久队列或外部 Collector。

采集准备、内容处理和 Handler 写入异常会被隔离，不会改变原业务请求、数据库查询、Redis 命令或 Guzzle Promise 的结果。内部诊断以单行文本输出，包含阶段、异常类型、消息位置和 request ID，不输出 stack trace。

## 内容保护

默认敏感字段包括 Authorization、Cookie、密码、Token、API Key 和 Secret 等常见名称。匹配不区分大小写，并递归处理 HTTP/SDK Header、URL query、JSON、表单和结构化字段。

- 显式 JSON 无法解析时采用 fail-closed，正文会被省略。
- Redis `AUTH` 始终遮蔽；其他 Redis 命令和 SQL 不做字段语义猜测。
- 可回绕流在读取后恢复位置；Hyperf `SwooleStream` 可以安全快照。
- 其他不可回绕流不会被消费。
- 文本超限时截断，结构化数据或流超限时省略。

保护动作统一写入 `payload_protection`：

```json
{
  "payload_protection": [
    {
      "path": "response.body",
      "action": "omitted",
      "reason": "limit_exceeded",
      "limit_bytes": 65536
    }
  ]
}
```

`action` 包括 `truncated`、`omitted`；`reason` 包括 `invalid_json`、`limit_exceeded`、`non_rewindable_stream`、`read_failed`。

字段脱敏不是全面的数据防泄漏方案。SQL 会记录插值后的 bindings，Redis 非 `AUTH` 命令会记录完整参数，异常消息、URL 路径和自由文本也不做敏感值语义识别。请根据数据分级启用采集器，尤其谨慎开启数据库、Redis 和响应详情。

## Guzzle、数据库和 Redis

### Guzzle 与 `hyperf/guzzle`

本包对 `GuzzleHttp\Client::__construct` 使用 AOP，在客户端构造完成后向现有 `HandlerStack` 添加 request ID 和日志 middleware：

- 不替换底层 handler，不修改 `timeout`、`connect_timeout` 或 `swoole` 选项。
- SDK 采集关闭时仍会透传当前 request ID。
- SDK 采集开启时，成功响应、HTTP 错误、同步异常和 Promise rejection 都会生成 `sdklog`。
- `Hyperf\Guzzle\ClientFactory` 通过容器创建 Client，因此其 `CoroutineHandler` 与日志切面可以同时正常工作。

如需使用 Hyperf 的 Guzzle 协程客户端，由宿主项目安装：

```bash
composer require hyperf/guzzle:^3.2
```

直接 `new GuzzleHttp\Client()` 绕过 Hyperf 容器/AOP，或使用裸 callable 作为 handler 时，不保证自动安装日志 middleware。自定义 handler 如需自动采集，应包装为 `HandlerStack`。

### Database

数据库采集仅监听查询完成后的 `QueryExecuted`，不会对数据库连接方法添加切面。在该事件派发前直接抛出的查询异常不会生成 `database.query` 日志。

### Redis

Redis 采集依赖 Hyperf Redis 的 `CommandExecuted` 事件。宿主必须启用 Redis 事件（通常设置 `REDIS_EVENT_ENABLE=true`）；只打开本包的 `collectors.redis.enabled` 不会让 Hyperf 开始派发事件。

## 开发验证

```bash
composer test
composer analyse
composer cs
composer check
composer benchmark -- --count=2000 --concurrency=16 --delay-us=1000
```

这些命令面向 GitHub 源码仓库；Composer dist 会排除测试和 benchmark。测试覆盖真实 Swoole 协程以及包内 API、Guzzle、Database、Redis、CLI 事件和 Handler 集成链路，不启动完整 Hyperf 服务，也不连接真实 MySQL 或 Redis。性能测试方法和参考基线见 [benchmark/README.md](https://github.com/sllhSmile/hyperf-log/blob/main/benchmark/README.md)。

## License

[MIT License](LICENSE)

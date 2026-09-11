# Hyperf Log

[![Latest Stable Version](https://img.shields.io/packagist/v/sllhsmile/hyperf-log.svg)](https://packagist.org/packages/sllhsmile/hyperf-log)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Hyperf](https://img.shields.io/badge/Hyperf-%5E3.2-0E8A16)](https://hyperf.io/)
[![License](https://img.shields.io/badge/license-MIT-22C55E)](LICENSE)

为 Hyperf 应用统一记录 HTTP API、数据库、Redis 和 Guzzle 调用日志，并用同一个
request ID 串联一次请求中的业务日志与下游调用。

安装后可按采集器独立启用日志；HTTP、CLI 和 Guzzle 的链路上下文由包自动维护，RPC
入口也可以显式初始化。结构化日志默认在独立子协程中完成脱敏、容量限制和写入，尽量
减少日志处理对业务协程的影响。

> 本包提供的是应用日志与 request ID 关联能力，不生成 tracing span 或 metrics，也不
> 保证进程异常退出前所有异步日志都已落盘，因此不应作为支付、审计等零丢失日志方案。

## 目录

- [核心能力](#核心能力)
- [兼容性](#兼容性)
- [安装](#安装)
- [五分钟完成首次记录](#五分钟完成首次记录)
- [选择需要的日志](#选择需要的日志)
- [request ID 与运行环境](#request-id-与运行环境)
- [Guzzle 超时](#guzzle-超时)
- [脱敏与容量限制](#脱敏与容量限制)
- [生产注意事项](#生产注意事项)
- [常见问题](#常见问题)

## 核心能力

| 能力 | 行为 |
| --- | --- |
| `apilog` | 记录 HTTP 请求、响应、异常和耗时 |
| `dblog` | 记录数据库连接、展开 bindings 后的 SQL、可选执行结果和耗时 |
| `redislog` | 记录 Redis 命令、参数、结果、异常和耗时 |
| `sdklog` | 记录 Guzzle 请求、可选响应、异常和耗时 |
| request ID | 接收入站 ID 或生成 UUID v7，并写入响应和 Guzzle 出站 Header |
| 内容保护 | 对 API、Guzzle、Redis 日志执行字段脱敏和负载截断 |
| 异步写入 | HTTP/RPC 协程中使用日志子协程；CLI 中同步写入 |

所有采集器默认关闭。启用哪些日志、写入哪个文件，完全由宿主应用的
`config/autoload/logger.php` 决定。

## 兼容性

| hyperf-log | PHP | Hyperf 组件 | Guzzle |
| --- | --- | --- | --- |
| `^0.5` | `>=8.2` | `^3.2` | `^7.0` |

这是 `composer.json` 声明的安装范围。当前版本要求 Hyperf 3.2，不兼容 Hyperf 3.0 或
3.1；项目使用的 `hyperf/command`、`context`、`coroutine`、`database`、`di`、`event`、
`guzzle`、`http-server`、`logger` 和 `redis` 组件需要能够统一解析到 3.2。

## 安装

包已发布到 Packagist：

```bash
composer require sllhsmile/hyperf-log:^0.5
```

Hyperf 会通过 Composer 自动发现 `Sllhsmile\HyperfLog\ConfigProvider`，无需手工注册。
随后发布链路和内容保护配置：

```bash
php bin/hyperf.php vendor:publish sllhsmile/hyperf-log --id=trace-log-config
```

配置将生成到 `config/autoload/trace_log.php`。本包没有必填环境变量，也不会覆盖现有的
`config/autoload/logger.php`。

## 五分钟完成首次记录

### 1. 开启 HTTP 请求生命周期事件

`apilog` 依赖 Hyperf 的 `RequestHandled` 事件。确认 HTTP server 的配置包含：

```php
'options' => [
    'enable_request_lifecycle' => true,
],
```

该配置位于 `config/autoload/server.php` 中对应 HTTP server 的配置项内。

### 2. 配置日志 channel

将下面的 `trace`、`apilog`、`redislog`、`sdklog` 和 `dblog` 合并到现有
`config/autoload/logger.php` 的 `channels` 中：

```php
<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Sllhsmile\HyperfLog\Formatter\CustomizeJsonFormatter;

return [
    'default' => 'default',
    'channels' => [
        // 保留或替换为应用原有的 default channel。
        'default' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => BASE_PATH . '/runtime/logs/hyperf.log',
                    'level' => Logger::INFO,
                ],
            ],
        ],

        // 四类采集器共用这套 Handler 和 JSON Formatter。
        'trace' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => BASE_PATH . '/runtime/logs/trace.log',
                    'level' => Logger::INFO,
                ],
            ],
            'formatter' => [
                'class' => CustomizeJsonFormatter::class,
            ],
        ],

        'apilog' => [
            'enabled' => true,
            'handlers' => ['trace'],
        ],
        'redislog' => [
            'enabled' => false,
            'handlers' => ['trace'],
        ],
        'sdklog' => [
            'enabled' => false,
            'response_enabled' => false,
            'handlers' => ['trace'],
        ],
        'dblog' => [
            'enabled' => false,
            'response_enabled' => false,
            'handlers' => ['trace'],
        ],
    ],
];
```

这个最小配置只启用 `apilog`，并将结构化日志写入 `runtime/logs/trace.log`。需要其他
采集器时，将对应的 `enabled` 改为 `true`。

### 3. 重启服务并验证

配置修改后需要重启 Hyperf Worker：

```bash
php bin/hyperf.php start
```

另开终端访问应用中任意已有路由：

```bash
curl -i -H 'x-b3-traceid: demo-trace-001' http://127.0.0.1:9501/
tail -n 1 runtime/logs/trace.log
```

响应 Header 中应包含 `x-b3-traceid: demo-trace-001`，日志中应出现
`"message_type":"apilog"` 和 `"request_id":"demo-trace-001"`。如果不主动传入
Header，本包会生成 UUID v7，并将最终值写回响应 Header。

## 选择需要的日志

| Channel | 默认状态 | `response_enabled` | 生产注意事项 |
| --- | --- | --- | --- |
| `apilog` | 关闭 | 不适用 | 请求体和响应体会被记录，并受 `payload.max_bytes` 限制 |
| `dblog` | 关闭 | 默认 `false` | SQL 会展开 bindings；当前不参与脱敏或截断 |
| `redislog` | 关闭 | 不适用 | 参数和结果会被记录；格式化命令存在下文所述边界 |
| `sdklog` | 关闭 | 默认 `false` | 请求始终记录；开启后才读取并记录响应体 |

`response_enabled` 配置在各自的 `logger.channels.dblog` 或
`logger.channels.sdklog` 下。建议生产环境保持关闭，确需响应内容时再独立开启。

如果需要分文件，可以为每个采集器提供独立 handler；如果已有共享 channel，也可以用
`channel` 映射：

```php
'apilog' => [
    'enabled' => true,
    'channel' => 'application-json',
],
```

## request ID 与运行环境

默认使用 `x-b3-traceid`：

- HTTP：保留有效的入站 Header；缺失或为空时生成 UUID v7，并写入请求上下文和响应。
- Guzzle：所有出站请求都覆盖为当前 `RequestContext` 的 ID，保证同一请求内只有一个 ID。
- CLI：`BeforeHandle` 监听器会在命令开始前自动初始化 ID。
- RPC 或后台协程：在入口注入 `RequestContext`，调用 `initializeTrace()`；有上游 ID 时将其
  作为参数传入。

业务代码需要读取当前 ID 时，可以直接注入公共服务：

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Sllhsmile\HyperfLog\Support\RequestContext;

final class CurrentTrace
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function id(): string
    {
        return $this->context->id();
    }
}
```

Header 名称和 Context 键可以在 `trace_log.php` 中分别修改。除非需要兼容已有链路规范，
建议保持二者一致。

## Guzzle 超时

`GuzzleLogAspect` 会作用于受 Hyperf AOP 管理的 `GuzzleHttp\Client`：无论 `sdklog` 是否
启用，都会注入 request ID、请求开始时间和超时中间件。

```php
'guzzle' => [
    'timeout' => 10,
    'connect_timeout' => 3,
],
```

两个值的单位都是秒：

- `timeout`：请求超时时间。
- `connect_timeout`：建立连接的超时时间。

它们只会写入 Hyperf `CoroutineHandler` 使用的 `swoole.timeout` 和
`swoole.connect_timeout`，不会修改普通 cURL Handler 的顶层超时。优先级从高到低为：

1. 单次请求显式传入的 `swoole.*`。
2. 单次请求顶层的 `timeout` / `connect_timeout`。
3. `trace_log.guzzle` 的公共值。
4. Swoole 默认行为。

不配置时，本包不会主动设置超时。

## 脱敏与容量限制

发布的 `trace_log.php` 默认包含：

| 配置 | 默认值 | 作用 |
| --- | --- | --- |
| `payload.sensitive_fields` | 常见凭据字段列表 | 按字段名忽略大小写脱敏 |
| `payload.redaction_value` | `****` | 敏感值的替换内容 |
| `payload.max_bytes` | `65536` | 单个负载字段允许记录的最大字节数 |

内置处理器覆盖 Header、URL Query、JSON 请求体、URL encoded 表单，以及已经转换为数组的
JSON 响应。发生截断时，字段会变为字符串预览，并在顶层增加元数据：

```json
{
    "response": "truncated content...",
    "payload_truncation": {
        "response": {
            "original_bytes": 183420
        }
    }
}
```

设置 `payload.sensitive_fields=[]` 会关闭通用字段脱敏；Redis `AUTH` 参数仍会强制遮蔽。
设置 `payload.max_bytes=null` 会关闭截断。

当前保护边界必须在生产使用前确认：

- `multipart/form-data` 和其他无法识别结构的原始请求体不会做字段级脱敏，只会截断。
- Redis `request.command` 是格式化后的展示字符串，除 `AUTH` 外不会重新解析；敏感值仍可能
  出现在其中。
- `dblog` 完全绕过内容处理；bindings 会展开进 SQL，SQL 或查询结果可能包含敏感数据。
- 异常消息中的任意文本不会根据内容猜测敏感值，仅按结构化字段名处理。

需要更严格规则时，在宿主 `config/autoload/dependencies.php` 中替换处理器：

```php
use App\Logging\PayloadProcessor;
use Sllhsmile\HyperfLog\Contract\PayloadProcessorInterface;

return [
    PayloadProcessorInterface::class => PayloadProcessor::class,
];
```

自定义实现接收的是父协程已经生成的数组/字符串快照；不要在处理器中读取 PSR-7 Stream
或其他请求期可变对象。

## 日志格式

`CustomizeJsonFormatter` 输出单行 JSON，并将采集器 context 平铺到顶层，方便日志平台
直接按字段检索。以下字段由 Formatter 保留，业务 context 不能覆盖：

- `datetime`
- `message_type`
- `request_id`
- `coroutine_id`

`message_type` 对应 `apilog`、`dblog`、`redislog` 或 `sdklog`。`app_name` 优先读取应用的
`app_name` 配置，未配置时回退到 `app_env`。

## 生产注意事项

- 日志通过子协程尽力写入，调用方不会显式等待子协程完成；Worker 被强制退出时，少量
  日志可能来不及落盘。
- 日志处理或写入失败不会中断业务。fallback 不复制 Header、Body 或 Query；SDK fallback
  额外保留 request ID、请求方法、移除 Query 和 Fragment 的 URL，以及异常摘要。
- 开启 `sdklog.response_enabled` 或 `dblog.response_enabled` 会增加内存、序列化与存储开销。
- 已有同类 Listener、全局 Middleware 或 Guzzle Aspect 时，应关闭其中一套，避免重复日志
  和重复 Header 注入。
- 修改配置或替换容器依赖后必须重启 Worker；Hyperf 长驻进程不会自动加载 PHP 配置变化。

## 常见问题

### 已启用 `apilog`，为什么没有日志？

确认 `logger.channels.apilog.enabled=true`、其 `handlers` 指向存在的 channel，并检查 HTTP
server 的 `options.enable_request_lifecycle=true`。修改后需要重启 Worker。

### 为什么没有 SDK 或数据库响应？

`logger.channels.sdklog.response_enabled` 和
`logger.channels.dblog.response_enabled` 默认都是 `false`，需要分别显式开启。

### 为什么配置的 Guzzle 超时对 cURL Handler 没有效果？

本包的公共超时只补充 Hyperf 协程 Handler 的 `swoole` 配置。普通 Guzzle/cURL 客户端请
继续通过客户端或单次请求的顶层 `timeout`、`connect_timeout` 配置。

## 测试

```bash
composer install
composer test
```

## 反馈与许可证

问题请提交到 [GitHub Issues](https://github.com/sllhSmile/hyperf-log/issues)。

本项目使用 [MIT License](LICENSE)。

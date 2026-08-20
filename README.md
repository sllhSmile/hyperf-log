# sllhsmile/hyperf-log

Hyperf 3 的结构化 API、Redis、数据库和 Guzzle 客户端日志公共包。

## 安装与配置

```bash
composer require sllhsmile/hyperf-log:^0.1
php bin/hyperf.php vendor:publish sllhsmile/hyperf-log --id=trace-log-config
```

该包要求 PHP `>=8.2` 和 Hyperf `^3.2`。Packagist 注册完成前，可在宿主项目的
`composer.json` 添加以下 VCS repository 后执行相同安装命令：

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/sllhSmile/hyperf-log.git"}
    ]
}
```

公共包不发布、也不会覆盖 logger 配置。请在宿主项目既有的
`config/autoload/logger.php` 的 `channels` 中，参考现有 `default`、`xthk` channel 的 handler 和 formatter
写法，新增以下四个 channel：

```php
'redislog' => ['enabled' => true, 'handlers' => ['default']],
'apilog' => ['enabled' => true, 'handlers' => ['default']],
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

`handlers => ['default']` 会复用 `channels.default` 的 `RotatingFileHandler`、formatter 和日志路径，因此四类
日志会统一写入 `file.log`。`enabled` 只控制同名公共包采集器；`dblog.response_enabled` 和
`sdklog.response_enabled` 默认关闭，避免记录大查询结果或大 HTTP 响应。

发布的 `config/autoload/trace_log.php` 管理共享 request-id 和 Guzzle 行为：

```php
return [
    'request_id_header' => 'x-b3-traceid',
    'request_id_context_key' => 'x-b3-traceid',
    'request_start_header' => 'x-request-start-time',
    'request_start_context_key' => 'request_start_time',
    'guzzle' => [
        'timeout' => 10,
        'connect_timeout' => 10,
    ],
];
```

## 行为与迁移

公共包安装后，Guzzle Client 始终会获得默认超时、request-id 和请求开始时间 Header。显式传入的
`timeout`、`connect_timeout`、request-id Header 均优先于公共包默认值。

`sdklog.enabled=true` 时记录 Guzzle 成功和异常调用；`apilog`、`redislog`、`dblog` 分别由自己的
channel 开关控制。至少启用一个采集器时，中间件会保留入站 request-id；缺失或为空时创建 UUID v7，并写入
协程上下文和入站请求 Header。

HTTP 的全局 `LogMiddleware` 会自动初始化 trace，它注册在 `middlewares.http`，覆盖 HTTP server 的所有
路由。命令行不会经过该中间件，而是通过 `BeforeHandle` 事件自动初始化，因此命令中产生的数据库、Redis、
SDK 日志也会关联同一个 request-id。RPC 同样不会自动经过 HTTP Middleware；当前项目未安装 RPC 组件。
未来接入 JSON-RPC/gRPC 后，请在其全局 middleware 的最前面注入
`Sllhsmile\HyperfLog\Support\RequestContext` 并调用 `initializeTrace()`；其后产生的日志和 Guzzle 调用将
自动复用同一 request-id。

HTTP/RPC 协程环境的日志写入会创建子协程，日志格式化和文件 IO 不阻塞当前业务协程；CLI 通常不在协程中，
采用同步且吞掉写入异常的兜底方式，保证命令主流程不因日志失败中断。

本包不会删除或禁用宿主项目已有的 Listener、Middleware、Aspect。若旧实现和本包的同类采集器同时
启用，日志会重复；请由接入方按需关闭其中一方。API 日志依赖 HTTP server 的
`enable_request_lifecycle=true`。请求/响应 body、headers、SQL bindings 和 Redis parameters 会原样记录；
生产环境应通过应用 formatter 或 processor 完成敏感数据脱敏。

## 替换已有日志实现

当前项目使用本地开发包时，可在根 `composer.json` 配置 `packages/hyperf-log` 的 `path` repository，并执行：

```bash
composer update sllhsmile/hyperf-log --with-all-dependencies
php bin/hyperf.php vendor:publish sllhsmile/hyperf-log --id=trace-log-config
rm -rf runtime/container
```

随后在 `logger.php` 启用四个同级 channel，并在应用的 listeners、middlewares、Guzzle 切面和自定义
HTTP Client 中注释旧日志注册/注入。保留旧类源码即可，不能让旧采集器和本包同时生效。

## 测试

公共包单元测试：

```bash
php vendor/bin/co-phpunit -c packages/hyperf-log/phpunit.xml --colors=always
```

本地运行服务后分别发起包含 HTTP、数据库、Redis 和 Guzzle 调用的请求；检查 `api.log`、`database.log`、
`redis.log`、`sdk.log`。dblog 的 `request.sql` 为完整明文 SQL，包含已展开的绑定参数；同一请求产生的四类
日志应具有相同 `request_id`，且每种日志仅写入一次。

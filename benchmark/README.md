# 采集日志性能验证

在包目录安装开发依赖后运行（官方 Swoole ≥5.0）：

```bash
composer benchmark -- --count=2000 --concurrency=16 --delay-us=1000
composer benchmark -- --count=2000 --concurrency=16 --delay-us=0
```

每条命令启动四个独立 PHP 进程，分别测试 `sync/async × wait/immediate`。`wait` 等待全部 Handler 写入完成并主动调用 dispatcher drain；`immediate` 在所有生产协程调用 `info()` 返回后，由父进程发送 SIGTERM，不等待异步队列，模拟非优雅终止，不代表 Hyperf Worker 的正常退出行为。

脚本使用真实 Hyperf Logger、内容保护、JSON Formatter 和临时文件 StreamHandler。`delay-us` 是 Handler 写入前的人工可让出延迟，默认 1,000 微秒，用于观察异步积压；0 表示不加人工延迟。没有模拟数据库、Redis 服务或网络日志后端，也没有验证物理 fsync。临时日志在测量后删除。

每组输出一行 JSON，包含提交耗时 `submit_ms`、完成耗时 `elapsed_ms`、提交/完成吞吐、PHP 峰值内存、Handler 观察到的峰值协程数、最终行数、唯一序号数、重复数和缺失数。`wait` 场景要求所有序号恰好写入一次；`immediate` 只记录终止时结果。内存不等于操作系统 RSS；立即终止没有完整完成耗时/吞吐保证。结果受主机负载、PHP、Swoole、hook 设置和文件系统影响，不设置共享 CI Runner 的绝对性能门槛。

## 历史基线

以下数据来自 0.7.1 的每条日志 `Coroutine::fork()` 实现；0.8 已改为单消费者有界队列，因此不能与新实现直接比较。重新评估请使用上面的命令记录新的环境基线。

| 人工延迟 µs | 模式 | 退出方式 | 提交 ms | 完成 ms | 峰值 PHP MiB | 峰值协程 | 写入/目标 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1000 | sync | wait | 228.708 | 228.846 | 8 | 17 | 2000/2000 |
| 1000 | sync | immediate | 232.167 | — | 8 | 17 | 2000/2000 |
| 1000 | async | wait | 149.583 | 262.460 | 38 | 2002 | 2000/2000 |
| 1000 | async | immediate | 182.515 | — | 36 | 2002 | 0/2000 |
| 0 | sync | wait | 95.966 | 96.117 | 8 | 17 | 2000/2000 |
| 0 | sync | immediate | 91.159 | — | 8 | 17 | 2000/2000 |
| 0 | async | wait | 204.473 | 301.170 | 38 | 2002 | 2000/2000 |
| 0 | async | immediate | 272.359 | — | 36 | 2002 | 0/2000 |

历史结果展示了每条 fork 的协程和内存成本。0.8 的正常等待应包含 drain；立即终止仍会展示 SIGTERM/OOM 等非优雅退出下的尾部日志风险。异步不必然提高最终完成吞吐；生产环境仍需使用实际 Handler 和负载进行验证。

<?php

declare(strict_types=1);

use Hyperf\Config\Config;
use Hyperf\Logger\Logger;
use Hyperf\Logger\LoggerFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Formatter\StructuredJsonFormatter;
use Sllhsmile\HyperfLog\Support\CollectorLogger;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\PayloadLimiter;
use Sllhsmile\HyperfLog\Support\PayloadProcessor;
use Sllhsmile\HyperfLog\Support\PayloadRedactor;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

require dirname(__DIR__) . '/tests/bootstrap.php';

$options = getopt('', ['worker', 'mode:', 'exit:', 'output:', 'count:', 'concurrency:', 'delay-us:']);
if ($options === false) {
    throw new RuntimeException('Cannot parse benchmark options.');
}

/** @param array<string, mixed> $options */
function benchmarkInteger(array $options, string $key, int $default, int $min, int $max): int
{
    $value = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT);
    if (! is_int($value) || $value < $min || $value > $max) {
        throw new InvalidArgumentException(sprintf('--%s must be between %d and %d.', $key, $min, $max));
    }

    return $value;
}

$count = benchmarkInteger($options, 'count', 2000, 1, 100000);
$concurrency = benchmarkInteger($options, 'concurrency', min(16, $count), 1, min(1024, $count));
$delayUs = benchmarkInteger($options, 'delay-us', 1000, 0, 100000);
if ($delayUs > 0 && $delayUs < 1000) {
    throw new InvalidArgumentException('--delay-us must be 0 or between 1000 and 100000.');
}

if (! isset($options['worker'])) {
    // 每组使用独立进程，避免 Swoole 运行状态和内存峰值相互污染。
    foreach (['sync', 'async'] as $mode) {
        foreach (['wait', 'immediate'] as $exitMode) {
            $output = tempnam(sys_get_temp_dir(), 'hyperf-log-benchmark-');
            if ($output === false) {
                throw new RuntimeException('Cannot create benchmark log file.');
            }
            $process = null;
            $pipes = [];
            try {
                $process = proc_open([
                    PHP_BINARY, __FILE__, '--worker', '--mode=' . $mode, '--exit=' . $exitMode,
                    '--output=' . $output, '--count=' . $count, '--concurrency=' . $concurrency,
                    '--delay-us=' . $delayUs,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
                if (! is_resource($process)) {
                    throw new RuntimeException('Cannot start benchmark worker.');
                }
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 60);
                $line = fgets($pipes[1]);
                if ($line === false) {
                    throw new RuntimeException('Benchmark worker did not return a result within 60 seconds.');
                }
                $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($result)) {
                    throw new RuntimeException('Invalid benchmark worker result.');
                }
                if ($exitMode === 'immediate') {
                    // 模拟进程终止，不等待异步队列；不是优雅退出测试。
                    proc_terminate($process);
                }
                fclose($pipes[1]);
                $exitCode = proc_close($process);
                $process = null;
                if ($exitMode === 'wait' && $exitCode !== 0) {
                    throw new RuntimeException('Benchmark worker failed with exit code ' . $exitCode);
                }
                $lines = file($output, FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    throw new RuntimeException('Cannot read benchmark log file.');
                }
                $result['written_lines'] = count($lines);
                $result['missing_lines'] = $count - count($lines);
                $sequences = [];
                foreach ($lines as $logLine) {
                    $record = json_decode($logLine, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($record) && is_int($record['benchmark_sequence'] ?? null)) {
                        $sequences[] = $record['benchmark_sequence'];
                    }
                }
                $uniqueSequences = array_unique($sequences);
                $result['unique_lines'] = count($uniqueSequences);
                $result['duplicate_lines'] = count($sequences) - count($uniqueSequences);
                echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                if ($exitMode === 'wait'
                    && (count($lines) !== $count || count($uniqueSequences) !== $count)) {
                    throw new RuntimeException('Normal completion lost or duplicated benchmark logs.');
                }
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                unlink($output);
            }
        }
    }
    exit(0);
}

$mode = $options['mode'] ?? null;
$exitMode = $options['exit'] ?? null;
$output = $options['output'] ?? null;
if (! in_array($mode, ['sync', 'async'], true)
    || ! in_array($exitMode, ['wait', 'immediate'], true)
    || ! is_string($output) || ! is_file($output)) {
    throw new InvalidArgumentException('Worker requires --mode, --exit and an existing --output file.');
}

$config = new LogConfig(new Config(['app_name' => 'benchmark', 'trace_log' => ['write_mode' => $mode]]));
$context = new RequestContext();
$finished = new Channel($count);
$handler = new class ($output, $finished, $delayUs) extends StreamHandler {
    public int $peakCoroutines = 0;

    public function __construct(string $output, private readonly Channel $finished, private readonly int $delayUs)
    {
        parent::__construct($output);
    }

    protected function write(LogRecord $record): void
    {
        $stats = Coroutine::stats();
        $this->peakCoroutines = max($this->peakCoroutines, $stats['coroutine_num']);
        // 模拟 Handler 的可让出 I/O，便于观察异步积压；0 可测纯本地文件写入。
        if ($this->delayUs > 0) {
            Coroutine::sleep($this->delayUs / 1000000);
        }
        parent::write($record);
        $this->finished->push(true);
    }
};
$handler->setFormatter(new StructuredJsonFormatter($context, $config));
$psrLogger = new Logger('apilog', [$handler]);
$factory = new class ($psrLogger) extends LoggerFactory {
    public function __construct(private readonly LoggerInterface $benchmarkLogger) {}

    public function get(string $name = 'hyperf', ?string $channel = null): LoggerInterface
    {
        return $this->benchmarkLogger;
    }
};
$logger = new CollectorLogger(
    $factory,
    $config,
    $context,
    new PayloadProcessor(new PayloadRedactor($config), new PayloadLimiter($config)),
);
$startedAt = hrtime(true);
$result = [];

\Swoole\Coroutine\run(function () use (
    $logger,
    $context,
    $finished,
    $handler,
    $count,
    $concurrency,
    $mode,
    $exitMode,
    $delayUs,
    $startedAt,
    &$result,
): void {
    $producersFinished = new Channel($concurrency);
    for ($worker = 0; $worker < $concurrency; ++$worker) {
        $id = Coroutine::create(static function () use ($logger, $context, $worker, $concurrency, $count, $producersFinished): void {
            $context->start('benchmark-' . $worker);
            for ($index = $worker; $index < $count; $index += $concurrency) {
                $logger->log(Level::Info, Collector::Api, [
                    'benchmark_sequence' => $index,
                    'request' => [
                        'method' => 'POST', 'url' => '/benchmark',
                        'headers' => ['content-type' => ['application/json']],
                        'body' => '{"password":"benchmark-secret","value":42}',
                    ],
                ]);
            }
            $producersFinished->push(true);
        });
        if ($id === false) {
            throw new RuntimeException('Cannot create benchmark producer coroutine.');
        }
    }
    for ($worker = 0; $worker < $concurrency; ++$worker) {
        $producersFinished->pop();
    }
    $submitMs = (hrtime(true) - $startedAt) / 1000000;
    if ($exitMode === 'wait') {
        for ($index = 0; $index < $count; ++$index) {
            if ($finished->pop(30) !== true) {
                throw new RuntimeException('Timed out waiting for benchmark writes.');
            }
        }
        $logger->drain();
    }
    $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
    $result = [
        'php' => PHP_VERSION, 'swoole' => phpversion('swoole'),
        'mode' => $mode, 'exit' => $exitMode, 'count' => $count, 'concurrency' => $concurrency,
        'handler_delay_us' => $delayUs, 'submit_ms' => round($submitMs, 3),
        'elapsed_ms' => round($elapsedMs, 3), 'enqueue_per_second' => round($count / ($submitMs / 1000)),
        'completed_per_second' => $exitMode === 'wait' ? round($count / ($elapsedMs / 1000)) : null,
        'peak_memory_bytes' => memory_get_peak_usage(true), 'peak_coroutines' => $handler->peakCoroutines,
    ];
    if ($exitMode === 'immediate') {
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        fflush(STDOUT);
        // 等待父进程立即终止本进程，不主动 drain 异步队列。
        (new Channel(1))->pop();
    }
});

$handler->close();
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;

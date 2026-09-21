<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Formatter;

use DateTimeZone;
use Hyperf\Coroutine\Coroutine;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Sllhsmile\HyperfLog\Context\RequestContext;
use Sllhsmile\HyperfLog\Enum\Collector;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\LogMetadata;

/**
 * 输出版本化的单行 JSON 日志。
 *
 * 内部采集日志使用扁平结构；普通应用日志保留 message 和嵌套 context。采集器身份只认
 * CollectorLogger 写入的元数据标记，避免业务 message 恰好等于 http.server 等类型时误判。
 * 采集日志的 request-id 与 coroutine-id 使用提交时快照，不能改读异步消费协程的 Context；
 * 普通应用日志没有该元数据时才读取格式化时的当前 Context。timestamp 固定转换为
 * Asia/Shanghai，便于当前业务日志直接检索和比对。
 */
final class StructuredJsonFormatter extends JsonFormatter
{
    /** 采集器上下文不得覆盖这些 envelope 字段。 */
    private const RESERVED = [
        'schema_version' => true,
        'timestamp' => true,
        'level' => true,
        'type' => true,
        'channel' => true,
        'service' => true,
        'request_id' => true,
        'coroutine_id' => true,
    ];

    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly RequestContext $requestContext,
        private readonly LogConfig $config,
    ) {
        $this->timezone = new DateTimeZone('Asia/Shanghai');
        parent::__construct(self::BATCH_MODE_JSON, true);
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $metadata = $context[Collector::LOG_METADATA_KEY] ?? null;
        unset($context[Collector::LOG_METADATA_KEY]);
        if (! $metadata instanceof LogMetadata) {
            $metadata = null;
        }
        $collector = $metadata?->collector;
        $output = [
            'schema_version' => 1,
            'timestamp' => $record->datetime->setTimezone($this->timezone)->format('Y-m-d H:i:s.uP'),
            'level' => $record->level->getName(),
            'type' => $collector?->type() ?? 'application',
            'channel' => $record->channel,
        ];
        $service = $this->config->service();
        if ($service !== '') {
            $output['service'] = $service;
        }
        $requestId = $metadata === null
            ? $this->requestContext->id()
            : $metadata->origin->requestId;
        if ($requestId !== null) {
            $output['request_id'] = $requestId;
        }
        $coroutineId = $metadata === null
            ? Coroutine::id()
            : $metadata->origin->coroutineId;
        if ($coroutineId >= 0) {
            $output['coroutine_id'] = $coroutineId;
        }

        if ($collector === null) {
            $output['message'] = $record->message;
            if ($context !== []) {
                $output['context'] = $context;
            }
        } else {
            foreach ($context as $key => $value) {
                if (! isset(self::RESERVED[$key]) && $value !== null) {
                    $output[$key] = $value;
                }
            }
        }

        return $this->toJson($this->normalize($output), true) . "\n";
    }
}

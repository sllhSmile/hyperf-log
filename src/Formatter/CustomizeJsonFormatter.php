<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Formatter;

use Hyperf\Coroutine\Coroutine;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Sllhsmile\HyperfLog\Support\LogConfig;
use Sllhsmile\HyperfLog\Support\RequestContext;

/**
 * JSON formatter for structured Hyperf logs.
 *
 * It intentionally flattens collector context so existing log platforms can
 * query request, response and timing fields without a context prefix.
 */
class CustomizeJsonFormatter extends JsonFormatter
{
    public function __construct(
        private readonly RequestContext $requestContext,
        private readonly LogConfig $config,
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = false,
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $newRecord = [
            'datetime' => $record->datetime->format('Y-m-d H:i:s'),
            'message_type' => $context === [] ? $this->config->appName() . '_log' : $record->message,
            'request_id' => $this->requestContext->id(),
            'coroutine_id' => Coroutine::id(),
        ];

        if ($context === []) {
            $newRecord['message'] = $record->message;
        } else {
            // 元数据为保留字段，不允许调用方 context 覆盖。
            $newRecord += $context;
        }

        return $this->toJson($this->normalize($newRecord), true) . ($this->appendNewline ? "\n" : '');
    }
}

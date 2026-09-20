<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging\Formatter;

use Firefly\Observability\Logging\ServiceContextLogProcessor;
use Firefly\Observability\Logging\TraceContextLogProcessor;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Elastic Common Schema 8 — the JSON shape Elastic's own ecs-logging libraries emit and Spring Boot's
 * `logging.structured.format=ecs` produces, first-party so the framework adds no logging dependency:
 *
 *   {"@timestamp":"2026-09-20T10:11:12.345678+00:00","log.level":"info","message":"Order 42 shipped",
 *    "ecs.version":"8.11.0","log":{"logger":"stack"},"service":{"name":"ledger","environment":"production"},
 *    "trace":{"id":"4bf9…"},"span":{"id":"00f0…"},"labels":{"correlation_id":"…","request_id":"…"},
 *    "error":{"type":"RuntimeException","message":"boom","stack_trace":"#0 …"},
 *    "context":{"order":42},"extra":{"memory":12}}
 *
 * The four framework ids and the two service fields are LIFTED out of extra into their ECS homes; whatever
 * else a processor or a caller added stays under `extra`/`context` — nested rather than merged at the top
 * level, so an application's `message` or `error` context key can never collide with an ECS field. A
 * Throwable in `context.exception` (Laravel's own convention) becomes `error.*` with the stack trace as text.
 * Levels are lowercase (`log.level: "warning"`), as ECS logging libraries write them.
 */
final class EcsFormatter extends NormalizerFormatter
{
    public const string ECS_VERSION = '8.11.0';

    public function __construct()
    {
        parent::__construct('Y-m-d\TH:i:s.uP');
    }

    public function format(LogRecord $record): string
    {
        /** @var array{datetime: string, context: array<string, mixed>, extra: array<string, mixed>} $normalized */
        $normalized = $this->normalizeRecord($record);
        $context = $normalized['context'];
        $extra = $normalized['extra'];

        $line = [
            '@timestamp' => $normalized['datetime'],
            'log.level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'ecs.version' => self::ECS_VERSION,
            'log' => ['logger' => $record->channel],
        ];

        $service = [];
        foreach ([ServiceContextLogProcessor::SERVICE_NAME => 'name', ServiceContextLogProcessor::SERVICE_ENVIRONMENT => 'environment'] as $field => $key) {
            if (isset($extra[$field]) && is_string($extra[$field])) {
                $service[$key] = $extra[$field];
                unset($extra[$field]);
            }
        }
        if ($service !== []) {
            $line['service'] = $service;
        }

        foreach ([TraceContextLogProcessor::TRACE_ID => 'trace', TraceContextLogProcessor::SPAN_ID => 'span'] as $field => $key) {
            if (isset($extra[$field]) && is_string($extra[$field])) {
                $line[$key] = ['id' => $extra[$field]];
                unset($extra[$field]);
            }
        }

        $labels = [];
        foreach ([TraceContextLogProcessor::CORRELATION_ID, TraceContextLogProcessor::REQUEST_ID] as $field) {
            if (isset($extra[$field]) && is_string($extra[$field])) {
                $labels[$field] = $extra[$field];
                unset($extra[$field]);
            }
        }
        if ($labels !== []) {
            $line['labels'] = $labels;
        }

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $line['error'] = [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'stack_trace' => $exception->getTraceAsString(),
            ];
            unset($context['exception']);
        }

        if ($context !== []) {
            $line['context'] = $context;
        }
        if ($extra !== []) {
            $line['extra'] = $extra;
        }

        return $this->toJson($line)."\n";
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging;

use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;

/**
 * A Monolog processor that stamps every log record with the request correlation id (Context 'firefly.correlation_id',
 * set by the M6 CorrelationIdFilter) — "surface the correlation id in logs" from the spec's tracing default.
 *
 * Deliberately renames the Context key ('firefly.correlation_id') to the shorter 'correlation_id' log field:
 * Laravel's OWN Illuminate\Log\Context\ContextLogProcessor already merges the ENTIRE Context::all() array into
 * every record's extra automatically (see Illuminate\Log\LogManager::get()), so 'firefly.correlation_id' is
 * already present verbatim — this processor's added value is purely the normalised, log-friendly field name a
 * log aggregator (e.g. an ELK/Loki query) expects, not first-time visibility of the value.
 */
final class CorrelationIdLogProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = Context::get('firefly.correlation_id');
        if (! is_string($correlationId) || $correlationId === '') {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'correlation_id' => $correlationId]);
    }
}

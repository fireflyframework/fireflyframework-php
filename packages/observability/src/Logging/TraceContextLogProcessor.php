<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging;

use Closure;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Web\TracingFilter;
use Firefly\Web\Filter\CorrelationIdFilter;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Monolog\LogRecord;

/**
 * Stamps every log record with the four ids that tie it to everything else — Spring Boot's
 * `[app,traceId,spanId]` MDC, as Monolog `extra` fields:
 *
 *   trace_id / span_id       the CURRENT span's ids when a tracer has one (a log line written inside a
 *                            command handler or an event listener names THAT span), else the request's ids
 *                            TracingFilter published in Context; absent when tracing is off.
 *   correlation_id           Context firefly.correlation_id (CorrelationIdFilter) — the id problem+json and
 *                            the X-Correlation-Id header carry; CorrelationIdLogProcessor writes the same
 *                            field and stays wired for applications that pushed it themselves.
 *   request_id               Context firefly.request_id (RequestContextFilter).
 *
 * The tracer is resolved through a closure on EVERY record, not captured at construction: the processor is
 * pushed when the `log` service is first resolved, which can be before the container has bound a Tracer at
 * all. The Context read is guarded exactly like CorrelationIdFilter::context(), so a record written from a
 * shutdown handler or a bare test cannot throw.
 */
final class TraceContextLogProcessor
{
    public const string TRACE_ID = 'trace_id';

    public const string SPAN_ID = 'span_id';

    public const string CORRELATION_ID = 'correlation_id';

    public const string REQUEST_ID = 'request_id';

    /** The Context key RequestContextFilter (firefly/web) seeds; a literal there, mirrored here. */
    private const string REQUEST_ID_CONTEXT_KEY = 'firefly.request_id';

    /** @param Closure(): ?Tracer $tracer */
    public function __construct(private readonly Closure $tracer) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        [$traceId, $spanId] = $this->spanIds();
        if ($traceId !== null) {
            $extra[self::TRACE_ID] = $traceId;
            if ($spanId !== null) {
                $extra[self::SPAN_ID] = $spanId;
            }
        }

        $correlationId = $this->context(CorrelationIdFilter::CONTEXT_KEY);
        if ($correlationId !== null) {
            $extra[self::CORRELATION_ID] = $correlationId;
        }

        $requestId = $this->context(self::REQUEST_ID_CONTEXT_KEY);
        if ($requestId !== null) {
            $extra[self::REQUEST_ID] = $requestId;
        }

        return $extra === $record->extra ? $record : $record->with(extra: $extra);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function spanIds(): array
    {
        $span = ($this->tracer)()?->currentSpan();
        if ($span !== null && $span->context()->isValid()) {
            return [$span->traceId(), $span->spanId()];
        }

        $traceId = $this->context(TracingFilter::CONTEXT_TRACE_ID);

        return $traceId === null ? [null, null] : [$traceId, $this->context(TracingFilter::CONTEXT_SPAN_ID)];
    }

    private function context(string $key): ?string
    {
        $app = Facade::getFacadeApplication();
        if ($app === null || ! $app->bound(ContextRepository::class)) {
            return null;
        }

        /** @var ContextRepository $repository */
        $repository = $app->make(ContextRepository::class);
        $value = $repository->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

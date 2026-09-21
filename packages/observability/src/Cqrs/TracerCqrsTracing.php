<?php

declare(strict_types=1);

namespace Firefly\Observability\Cqrs;

use Firefly\Cqrs\Tracing\CqrsTracing;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\Tracer;

/**
 * The real CqrsTracing — MeterRegistryCqrsMetrics's twin: an INTERNAL span around every command and query the
 * buses dispatch, named by the message's short class name (the same `type` the metrics tag uses, so a span and
 * its timer line up) with the fully-qualified class as an attribute for the day two namespaces share a name.
 * Tracer::trace() records a throwable as an ERROR status plus an exception event and rethrows, so a refused
 * command is on the trace with its reason.
 */
final class TracerCqrsTracing implements CqrsTracing
{
    public function __construct(private readonly Tracer $tracer) {}

    /**
     * @template T
     *
     * @param  callable(): T  $invocation
     * @return T
     */
    public function traceCommand(object $command, callable $invocation): mixed
    {
        return $this->tracer->trace($this->shortName($command), $invocation, SpanKind::Internal, [
            'firefly.cqrs.kind' => 'command',
            'firefly.cqrs.message' => $command::class,
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $invocation
     * @return T
     */
    public function traceQuery(object $query, callable $invocation): mixed
    {
        return $this->tracer->trace($this->shortName($query), $invocation, SpanKind::Internal, [
            'firefly.cqrs.kind' => 'query',
            'firefly.cqrs.message' => $query::class,
        ]);
    }

    private function shortName(object $message): string
    {
        $class = $message::class;

        return ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
    }
}

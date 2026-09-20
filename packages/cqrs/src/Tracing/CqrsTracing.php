<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tracing;

/**
 * The tracing SEAM both buses run their pipeline through — CqrsMetrics's sibling, with the one difference a
 * span needs: it WRAPS the work rather than being told about it afterwards, so an implementation can start a
 * span before validation and end it after the handler. NoOpCqrsTracing is the shipped default; the real
 * implementation (an INTERNAL span per message over the Tracer port) lives in firefly/observability and wins
 * by bean precedence, exactly like MeterRegistryCqrsMetrics. Nothing in firefly/cqrs depends on observability.
 */
interface CqrsTracing
{
    /**
     * @template T
     *
     * @param  callable(): T  $invocation
     * @return T
     */
    public function traceCommand(object $command, callable $invocation): mixed;

    /**
     * @template T
     *
     * @param  callable(): T  $invocation
     * @return T
     */
    public function traceQuery(object $query, callable $invocation): mixed;
}

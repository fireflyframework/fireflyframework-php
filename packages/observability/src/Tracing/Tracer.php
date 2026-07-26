<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * The tracing port. M12 ships only NoOpTracer (has_otel()-style guard); the OpenTelemetry adapter drops in at SP-7,
 * so instrumentation is written once against this interface today.
 */
interface Tracer
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function trace(string $name, callable $callback): mixed;
}

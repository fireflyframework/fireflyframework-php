<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/** The shipped tracer: runs the callback with no span. SP-7 replaces it with an OTel-backed adapter. */
final class NoOpTracer implements Tracer
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function trace(string $name, callable $callback): mixed
    {
        return $callback();
    }
}

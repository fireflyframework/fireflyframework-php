<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tracing;

/** The default CqrsTracing: runs the invocation with no span. The buses call it unconditionally so observability is a bean swap. */
final class NoOpCqrsTracing implements CqrsTracing
{
    /**
     * @template T
     *
     * @param  callable(): T  $invocation
     * @return T
     */
    public function traceCommand(object $command, callable $invocation): mixed
    {
        return $invocation();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $invocation
     * @return T
     */
    public function traceQuery(object $query, callable $invocation): mixed
    {
        return $invocation();
    }
}

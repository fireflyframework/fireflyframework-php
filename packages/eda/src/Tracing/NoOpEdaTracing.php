<?php

declare(strict_types=1);

namespace Firefly\Eda\Tracing;

use Firefly\Eda\EventEnvelope;

/** The default EdaTracing: sends with the headers it was given and delivers directly. Observability is a bean swap. */
final class NoOpEdaTracing implements EdaTracing
{
    /**
     * @param  array<string, string>  $headers
     * @param  callable(array<string, string>): void  $send
     */
    public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
    {
        $send($headers);
    }

    /** @param callable(EventEnvelope): void $deliver */
    public function traceConsume(EventEnvelope $envelope, callable $deliver): void
    {
        $deliver($envelope);
    }
}

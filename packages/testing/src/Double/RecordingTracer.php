<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Observability\Tracing\Tracer;

/** Records span names while faithfully invoking + returning the traced callback (like NoOpTracer, but observable). */
final class RecordingTracer implements Tracer
{
    /** @var list<string> */
    public array $spans = [];

    public function trace(string $name, callable $callback): mixed
    {
        $this->spans[] = $name;

        return $callback();
    }
}

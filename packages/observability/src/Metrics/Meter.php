<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** A registered meter — identified by name + sorted tag set + type. */
interface Meter
{
    public function name(): string;

    /** @return array<string, string> sorted by key */
    public function tags(): array;

    public function type(): MeterType;
}

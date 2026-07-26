<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

use Closure;

/** A supplier-backed gauge (Micrometer-style): sampled at read time, so it always reflects the current value. */
final class Gauge implements Meter
{
    /** @var Closure(): float */
    private Closure $supplier;

    /**
     * @param  array<string, string>  $tags
     * @param  callable(): float  $supplier
     */
    public function __construct(
        private readonly string $name,
        private readonly array $tags,
        callable $supplier,
    ) {
        $this->supplier = Closure::fromCallable($supplier);
    }

    public function value(): float
    {
        return ($this->supplier)();
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function type(): MeterType
    {
        return MeterType::Gauge;
    }
}

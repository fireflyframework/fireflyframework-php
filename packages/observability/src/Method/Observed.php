<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

use Attribute;

/**
 * Micrometer's Observation API in one attribute: ONE name that starts BOTH a span and a timer, which is the
 * whole point of `Observation` — a team should not have to name the same unit of work twice and then find
 * out in production that the two names disagree.
 *
 * `name` is the metric name (empty → `firefly.observability.method.observed.name`, default
 * `method.observed`); `contextualName` is the span name (empty → the metric name), because a span name is
 * read by a person in a waterfall and a meter name is read by a query language, and Micrometer keeps them
 * separable for that reason. `lowCardinalityKeyValues` are put on BOTH — they are called low-cardinality
 * precisely because a meter tag cannot afford anything else; nothing here ever puts an argument value on a
 * meter.
 *
 * The span is only started when a Tracer is bound and recording; with tracing off this degrades to exactly
 * what #[Timed] does, which is the honest reading of "an Observation is a timer plus a span".
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Observed
{
    /** @param array<string, string> $lowCardinalityKeyValues */
    public function __construct(
        public string $name = '',
        public string $contextualName = '',
        public array $lowCardinalityKeyValues = [],
    ) {}
}

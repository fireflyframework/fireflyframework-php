<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

use Attribute;

/**
 * Micrometer's `@Timed`, ported: a timer around the call, and on a throw the same timer tagged with the
 * exception class. `value` names the meter (empty → `firefly.observability.method.timed.name`, default
 * `method.timed`); `extraTags` are merged over the `class`/`method`/`exception` tags the interceptor always
 * writes; `description` is carried for the exposition's `# HELP` line.
 *
 * `longTask` is Micrometer's LongTaskTimer, reduced to the half this registry can honestly publish: a
 * set-gauge named `<meter>.active` holding the number of in-flight invocations, incremented on entry and
 * decremented in a `finally`. The other half — sampling the duration of a task that has not finished —
 * needs a meter type `MeterRegistry` does not have, so it is documented rather than faked.
 *
 * `percentiles` is ACCEPTED BY THE CONSTRUCTOR AND REFUSED BY THE SCAN, on purpose. Client-side quantile
 * summaries are a documented Known-latent of this package (there is no sliding-window percentile meter), so
 * a `percentiles:` that compiled and was then honoured by nothing would be a silent lie of exactly the kind
 * MethodSecurityScanner refuses for an unenforceable rule. Having the parameter at all means a person who
 * writes what Micrometer taught them gets a ConfigurationException naming
 * `firefly.observability.metrics.distribution.per-meter` — the histogram buckets Prometheus aggregates
 * percentiles from — instead of a fatal about an unknown named argument.
 *
 * Both targets, because Micrometer allows both: a class-level attribute applies to every public method of
 * the class, and a method-level one REPLACES it for that method (the same replacement rule
 * MethodSecurityScanner applies to #[PreAuthorize]).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Timed
{
    /**
     * @param  array<string, string>  $extraTags
     * @param  list<float>  $percentiles
     */
    public function __construct(
        public string $value = '',
        public array $extraTags = [],
        public string $description = '',
        public bool $longTask = false,
        public array $percentiles = [],
    ) {}
}

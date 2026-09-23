<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

use Attribute;

/**
 * Micrometer's `@Timed`, ported: a timer around the call, and on a throw the same timer tagged with the
 * exception class. `value` names the meter (empty → `firefly.observability.method.timed.name`, default
 * `method.timed`); `extraTags` are merged over the `class`/`method`/`exception` tags the interceptor always
 * writes.
 *
 * `longTask` is Micrometer's LongTaskTimer, reduced to the half this registry can honestly publish: a
 * set-gauge named `<meter>.active`, tagged exactly as the timer is, holding the number of invocations THIS
 * PROCESS has in flight — incremented on entry and decremented in a `finally`, so a recursive or re-entered
 * long task reads 2 rather than dropping to 0 the moment the inner call returns. The other half — sampling
 * the duration of a task that has not finished — needs a meter type `MeterRegistry` does not have, so it is
 * documented rather than faked.
 *
 * ACROSS PROCESSES the gauge is last-writer-wins, the Known-latent every set-gauge in this package shares:
 * with `firefly.observability.metrics.store` configured the shared key carries the depth of whichever worker
 * wrote last, not the fleet's total, because a gauge has no atomic increment to sum one. Read it as "this
 * meter has work in flight somewhere", not as a fleet-wide count.
 *
 * `percentiles` AND `description` are ACCEPTED BY THE CONSTRUCTOR AND REFUSED BY THE SCAN, on purpose, and
 * for one reason: a parameter that compiled and was then honoured by nothing would be a silent lie of
 * exactly the kind MethodSecurityScanner refuses for an unenforceable rule. Having them at all means a
 * person who writes what Micrometer taught them gets a ConfigurationException they can act on instead of a
 * fatal about an unknown named argument.
 *
 * `percentiles` is refused because client-side quantile summaries are a documented Known-latent of this
 * package (there is no sliding-window percentile meter); the message names
 * `firefly.observability.metrics.distribution.per-meter` — the histogram buckets Prometheus aggregates
 * percentiles from.
 *
 * `description` is refused because THIS registry's exposition has nowhere to put it. Micrometer carries a
 * meter description to the `# HELP` line, but PrometheusTextFormat synthesises that line from the sanitised
 * family name and the family's type (`# HELP orders_place orders_place (timer)`), and no seam runs from a
 * per-method descriptor to the exposition — MetricsRecorder::record() takes a name, tags and a duration, and
 * a description is family-level metadata a sample-level port cannot carry. So it is refused rather than
 * compiled into a descriptor row nothing reads. Put the sentence in a docblock on the method instead.
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

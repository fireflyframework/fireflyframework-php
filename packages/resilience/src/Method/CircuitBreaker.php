<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;

/**
 * Resilience4j's `@CircuitBreaker`, ported: the guarded method runs through the
 * `firefly.resilience.circuit-breaker.<name>` instance the ResilienceRegistry already builds, which refuses
 * the call outright while the breaker is open. The attribute carries ONE thing — the instance name —
 * because every knob (`failure-threshold`, `failure-rate-threshold`, `window-size`, `wait-duration-in-open`,
 * `half-open-max-calls`, `half-open-probe-timeout`, `minimum-number-of-calls`, `record-on`) already lives
 * in configuration, where an operator can widen a window at 3am without a deploy. That is Resilience4j's
 * own split, and it is the reason this attribute wraps the programmatic component rather than
 * reimplementing a policy: there is exactly one CircuitBreaker in this package, its state lives in the
 * shared ResilienceStore, and both call paths trip the SAME breaker — an attribute on a method and a
 * hand-written `$registry->circuitBreaker()` beside it are not two breakers that happen to share a name.
 *
 * A name with no configured instance is a ConfigurationException from the registry on the first call, which
 * names the instance and lists the ones that do exist.
 *
 * Both targets: a class-level attribute applies to every public method, a method-level one replaces it.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class CircuitBreaker
{
    public function __construct(public string $name) {}
}

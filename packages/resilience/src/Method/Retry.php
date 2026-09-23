<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;

/**
 * Resilience4j's `@Retry`, ported: the guarded method is re-invoked by the `firefly.resilience.retry.<name>`
 * instance the ResilienceRegistry already builds. The attribute carries ONE thing — the instance name —
 * because every knob (`max-attempts`, `wait-duration`, `backoff-multiplier`, `max-wait`, `jitter`,
 * `retry-on`) already lives in configuration, where an operator can change it without a deploy. That is
 * Resilience4j's own split, and it is the reason this attribute wraps the programmatic component rather
 * than reimplementing a policy: there is exactly one Retry in this package, and both call paths run it.
 *
 * A name with no configured instance is a ConfigurationException from the registry on the first call, which
 * names the instance and lists the ones that do exist.
 *
 * Both targets: a class-level attribute applies to every public method, a method-level one replaces it.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Retry
{
    public function __construct(public string $name) {}
}

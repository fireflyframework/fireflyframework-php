<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;

/**
 * Resilience4j's `@Bulkhead`, ported: the guarded method must hold one of the
 * `firefly.resilience.bulkhead.<name>` instance's permits for the length of the call, and is refused with a
 * BulkheadFullException when none is free. The attribute carries ONE thing — the instance name — because
 * every knob (`max-concurrent-calls`, `max-wait-duration`) already lives in configuration, where an operator
 * can widen a pool without a deploy. That is Resilience4j's own split, and it is the reason this attribute
 * wraps the programmatic component rather than reimplementing a policy: there is exactly one Bulkhead in
 * this package, its permit count lives in the shared ResilienceStore, and both call paths draw on the SAME
 * permits — which is the whole point of a bulkhead and would be untrue of a second implementation.
 *
 * A name with no configured instance is a ConfigurationException from the registry on the first call, which
 * names the instance and lists the ones that do exist.
 *
 * Both targets: a class-level attribute applies to every public method, a method-level one replaces it.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Bulkhead
{
    public function __construct(public string $name) {}
}

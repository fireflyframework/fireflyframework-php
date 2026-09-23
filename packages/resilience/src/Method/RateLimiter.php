<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;

/**
 * Resilience4j's `@RateLimiter`, ported: the guarded method must first take a token from the
 * `firefly.resilience.rate-limiter.<name>` instance the ResilienceRegistry already builds. The attribute
 * carries ONE thing — the instance name — because every knob (`max-tokens`, `refill-rate`, `timeout`)
 * already lives in configuration, where an operator can raise a quota without a deploy. That is
 * Resilience4j's own split, and it is the reason this attribute wraps the programmatic component rather
 * than reimplementing a policy: there is exactly one RateLimiter in this package, its bucket lives in the
 * shared ResilienceStore, and both call paths draw from the SAME bucket.
 *
 * A name with no configured instance is a ConfigurationException from the registry on the first call, which
 * names the instance and lists the ones that do exist.
 *
 * Both targets: a class-level attribute applies to every public method, a method-level one replaces it.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class RateLimiter
{
    public function __construct(public string $name) {}
}

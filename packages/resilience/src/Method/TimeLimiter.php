<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;

/**
 * Resilience4j's `@TimeLimiter`, ported: the guarded method is run under the
 * `firefly.resilience.time-limiter.<name>` instance the ResilienceRegistry already builds, which raises a
 * TimeoutException when the call overruns. The attribute carries ONE thing — the instance name — because
 * the knob (`timeout`) already lives in configuration, where an operator can loosen a deadline without a
 * deploy. That is Resilience4j's own split, and it is the reason this attribute wraps the programmatic
 * component rather than reimplementing a policy: there is exactly one TimeLimiter in this package, and both
 * call paths run it.
 *
 * NOTE, and it is the reason this attribute is not a promise: under PHP-FPM the limiter cannot PREEMPT a
 * blocking call — there is no pcntl, so the elapsed wall-clock is measured after the callable returns and
 * TimeoutException is raised post-hoc. On CLI, queue-worker and scheduled-task processes a SIGALRM does
 * interrupt. That limit is the programmatic component's and this attribute inherits it exactly; it is a
 * platform limit, not something the attribute layer can fix.
 *
 * A name with no configured instance is a ConfigurationException from the registry on the first call, which
 * names the instance and lists the ones that do exist.
 *
 * Both targets: a class-level attribute applies to every public method, a method-level one replaces it.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class TimeLimiter
{
    public function __construct(public string $name) {}
}

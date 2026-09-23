<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\Resilience\Scanner\ResilienceMethodScanner;

/**
 * firefly/resilience's contribution to the proxy plan — ObservabilityAdviceSource's shape, two layers
 * further in. The rows ResilienceMethodScanner::scanProxyAdvice() selects are baked into the generated proxy
 * as `ResilienceMethodDescriptor::fromArray([...])` literals and run by ResilienceMethodInterceptor at
 * advice order 200: INSIDE method security (100), so a call a #[PreAuthorize] refuses never spends a retry
 * budget, a bulkhead permit or a breaker outcome, and OUTSIDE the transaction (1000), so each retry attempt
 * opens a transaction of its own instead of re-running inside one that is already doomed. Both halves of
 * that argument, and the composition of the six patterns WITHIN this one link, are set out in full on
 * ResilienceMethodInterceptor — this class states the number and points there rather than restating it.
 *
 * An unconditional #[Component], exactly as security's and observability's are and for the same reason: the
 * PLAN is a compiled artifact and must not differ between the machine that ran `firefly:cache` and the
 * machine that boots. What the advice DOES is decided at wrap time by whether the interceptor bean exists —
 * and because it exists only while `firefly.resilience.method.enabled` is on, the advice declares itself
 * inert when unbound so InterceptorRegistry hands the proxy a PassThroughInterceptor instead of refusing the
 * boot. An application that switched the mechanism off asked for inert attributes, not a boot failure.
 */
#[Component]
final class ResilienceAdviceSource implements AdviceSource
{
    public const string ID = 'resilience';

    public function advice(): Advice
    {
        return new Advice(self::ID, ResilienceMethodInterceptor::class, ResilienceMethodDescriptor::class, 200, inertWhenUnbound: true);
    }

    public function scan(array $psr4): array
    {
        return (new ResilienceMethodScanner)->scanProxyAdvice($psr4);
    }

    public function render(array $row): string
    {
        return '\\'.ResilienceMethodDescriptor::class.'::fromArray('.var_export($row, true).')';
    }
}

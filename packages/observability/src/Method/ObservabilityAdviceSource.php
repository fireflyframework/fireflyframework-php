<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;

/**
 * firefly/observability's contribution to the proxy plan — MethodSecurityAdviceSource's shape, one layer
 * further out. The rows ObservabilityMethodScanner::scanProxyAdvice() selects are baked into the generated
 * proxy as `ObservabilityMethodDescriptor::fromArray([...])` literals and run by
 * ObservabilityMethodInterceptor at advice order 50 — OUTSIDE security (100) and outside the transaction
 * (1000), so a timer measures the refusal and the commit as well as the method. That ordering is argued in
 * full in ObservabilityMethodInterceptor's docblock, including why it is a deliberate DIVERGENCE from
 * Micrometer's own aspects — which are unordered and therefore run innermost, inside Spring Security's
 * interceptors — rather than the parity the rest of this package is.
 *
 * An unconditional #[Component], exactly as security's is and for the same reason: the PLAN is a compiled
 * artifact and must not differ between the machine that ran `firefly:cache` and the machine that boots. What
 * the advice DOES is decided at wrap time by whether the interceptor bean exists — and because it exists
 * only while metrics and `firefly.observability.method.enabled` are on, the advice declares itself inert
 * when unbound so InterceptorRegistry hands the proxy a PassThroughInterceptor instead of refusing the boot.
 */
#[Component]
final class ObservabilityAdviceSource implements AdviceSource
{
    public const string ID = 'metrics';

    public function advice(): Advice
    {
        return new Advice(self::ID, ObservabilityMethodInterceptor::class, ObservabilityMethodDescriptor::class, 50, inertWhenUnbound: true);
    }

    public function scan(array $psr4): array
    {
        return (new ObservabilityMethodScanner)->scanProxyAdvice($psr4);
    }

    public function render(array $row): string
    {
        return '\\'.ObservabilityMethodDescriptor::class.'::fromArray('.var_export($row, true).')';
    }
}

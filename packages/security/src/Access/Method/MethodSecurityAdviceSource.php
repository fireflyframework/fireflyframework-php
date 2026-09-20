<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\Security\Scanner\MethodSecurityScanner;

/**
 * firefly/security's contribution to the proxy plan: the rows MethodSecurityScanner::scanProxyAdvice() selects
 * (stereotyped beans whose rules no dispatch seam enforces), baked into the proxy as
 * `SecurityMethodDescriptor::fromArray([...])` literals and run by MethodSecurityInterceptor at order 100 —
 * outside the transactional advice. It is an unconditional #[Component] on purpose: the PLAN is a compiled
 * artifact and must not change with configuration; whether the advice does anything is decided at wrap time
 * by whether the interceptor bean exists (see InterceptorRegistry).
 */
#[Component]
final class MethodSecurityAdviceSource implements AdviceSource
{
    public const string ID = 'security';

    public function advice(): Advice
    {
        // inertWhenUnbound: the interceptor bean exists only under the master flag, and its absence is the
        // documented "annotations are inert until security is enabled" state, not a misconfiguration — so the
        // InterceptorRegistry may hand the proxy a PassThroughInterceptor instead of refusing to boot.
        return new Advice(self::ID, MethodSecurityInterceptor::class, SecurityMethodDescriptor::class, 100, inertWhenUnbound: true);
    }

    public function scan(array $psr4): array
    {
        return (new MethodSecurityScanner)->scanProxyAdvice($psr4);
    }

    public function render(array $row): string
    {
        return '\\'.SecurityMethodDescriptor::class.'::fromArray('.var_export($row, true).')';
    }
}

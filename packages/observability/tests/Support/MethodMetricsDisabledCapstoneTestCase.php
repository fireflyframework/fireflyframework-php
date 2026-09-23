<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

/**
 * The same boot with `firefly.observability.method.enabled` OFF: the PLAN is unchanged — it is a compiled
 * artifact and does not move with configuration — so the fixture is still proxied, and the one thing that
 * differs is that the interceptor #[Bean] was conditioned away and InterceptorRegistry handed the proxy a
 * PassThroughInterceptor. That is the pass-through path, and it is the one thing a unit test cannot prove:
 * a unit test constructs the interceptor it is testing, so it can never observe the interceptor's absence.
 */
abstract class MethodMetricsDisabledCapstoneTestCase extends MethodMetricsCapstoneTestCase
{
    protected function methodMetricsEnabled(): bool
    {
        return false;
    }
}

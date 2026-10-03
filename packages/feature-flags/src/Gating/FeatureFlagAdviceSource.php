<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\FeatureFlags\Scanner\FeatureFlagScanner;

/**
 * firefly/feature-flags' contribution to the proxy plan, at advice order 80 (see FeatureFlagMethodInterceptor).
 * An unconditional #[Component] like every other source, because the plan is a compiled artifact. An unbound
 * interceptor stops boot: a declared gate must never silently become a pass-through.
 */
#[Component]
final class FeatureFlagAdviceSource implements AdviceSource
{
    public const string ID = 'featureflag';

    public const int ORDER = 80;

    public function advice(): Advice
    {
        return new Advice(self::ID, FeatureFlagMethodInterceptor::class, FeatureFlagMethodDescriptor::class, self::ORDER);
    }

    public function scan(array $psr4): array
    {
        return (new FeatureFlagScanner)->scanProxyAdvice($psr4);
    }

    public function render(array $row): string
    {
        return '\\'.FeatureFlagMethodDescriptor::class.'::fromArray('.var_export($row, true).')';
    }
}

<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\FeatureFlags\Gating\BladeDirectives;
use Firefly\FeatureFlags\Gating\FeatureFlagRouteGatingPass;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * The boot-pass half of firefly/feature-flags. Beans are described by the package manifests.
 */
final class FeatureFlagsWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new FeatureFlagRouteGatingPass];
    }

    public function boot(): void
    {
        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            BladeDirectives::register($blade);
        });
    }
}

<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot-pass half of firefly/feature-flags: route gating, the sync route, migrations, Blade directives and
 * the console command are added here by the lanes that own them. Nothing is bound here — every collaborator
 * is a #[Bean] or a #[Component] described by the package manifests.
 */
final class FeatureFlagsWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }

    public function boot(): void {}
}

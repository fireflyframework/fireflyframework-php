<?php

declare(strict_types=1);

namespace Firefly\Cli\Boot;

use Illuminate\Support\ServiceProvider;

/**
 * Boots the compiled cache: when firefly:cache has emitted artifacts into the app cache dir, binds the
 * Category-B wiring manifests ($app->instance over each *WiringProvider's bound()-guarded empty default)
 * and registers the #[Transactional] proxy autoloader. A pure no-op when the app is uncached (dev), so the
 * in-process scan fallback still applies. Filled in Task 3.
 */
final class FireflyCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Task 3.
    }
}

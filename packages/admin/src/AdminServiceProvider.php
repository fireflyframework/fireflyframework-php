<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Firefly\Admin\Boot\AdminRouteRegistrar;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The discovered admin provider (extra.laravel.providers). Contributes the route registrar and registers the
 * package's Blade views under the `firefly-admin::` namespace so they ship inside the package — an
 * application never has to publish or configure anything to get the dashboard.
 *
 * The views are plain Blade with inline CSS and no build step: a composer package cannot assume npm has run,
 * and a dashboard that needs a CDN at request time is useless in exactly the network-isolated environments
 * where you most want to look at one.
 */
final class AdminServiceProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'firefly-admin');

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new AdminRouteRegistrar];
    }
}

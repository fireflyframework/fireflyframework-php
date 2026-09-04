<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Firefly\Admin\Boot\AdminRouteRegistrar;
use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Admin\Settings\SettingsSettings;
use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

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

        $this->registerSettingsConsole();

        parent::register();
    }

    /**
     * The feature-switch console, and its overrides applied.
     *
     * IT HAPPENS IN register(), WHICH IS THE POINT. Every settings object in the framework is built ONCE
     * from configuration and held for the process — OpenApiProperties, AdminSettings, the actuator's
     * exposure model — so an override merged after the first of them is read is a value this page reports
     * and the application does not use. That was not a hypothesis: applying it from the dashboard's own boot
     * pass wrote the file, showed the new state on the page, and left /openapi.json answering 200 with the
     * switch reading "off". register() runs before any boot pass and before any bean resolves, which is the
     * only place the merge is true.
     */
    private function registerSettingsConsole(): void
    {
        $this->app->singleton(SettingsConsole::class, function (): SettingsConsole {
            /** @var ConfigRepository $repository */
            $repository = $this->app->make('config');
            $config = new Config($repository);

            return new SettingsConsole(
                $config,
                $repository,
                SettingsSettings::fromConfig($config),
                // bootstrapPath() is on the Application contract, and $this->app is typed as one — the
                // instanceof would always be true and PHPStan says so.
                (string) $this->app->bootstrapPath('cache'),
            );
        });

        $console = $this->app->make(SettingsConsole::class);

        if ($console->isEnabled()) {
            $console->apply();
        }
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new AdminRouteRegistrar];
    }
}

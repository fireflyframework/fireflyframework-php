<?php

declare(strict_types=1);

namespace Firefly\Admin\Boot;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Admin\AdminEndpointReader;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\Data\ConnectionWizard;
use Firefly\Admin\Data\DataBrowser;
use Firefly\Admin\Data\DataBrowserSettings;
use Firefly\Admin\Data\DataQueryEngine;
use Firefly\Admin\Data\DataResourceRegistry;
use Firefly\Admin\Data\DataSchemaFactory;
use Firefly\Admin\Data\DatasourceReport;
use Firefly\Admin\Data\RepositoryIntrospector;
use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Admin\Web\AdminAction;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Mounts the dashboard on the illuminate Router at phase WiringPasses, order 60 — after
 * ActuatorRouteRegistrar's 50, because the dashboard reads the ActuatorRegistry that pass populates.
 *
 * Two routes under a configurable base path, which is why they are registered here rather than declared with
 * #[GetMapping]: attribute routes compile into the RouteManifest with a literal path, and
 * `firefly.admin.base-path` has to be settable per application. Same reasoning, same shape, as the actuator's
 * own registrar.
 *
 * Registers nothing at all when the dashboard is disabled — see AdminSettings for why that is the default
 * outside debug.
 */
final class AdminRouteRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 60;
    }

    public function run(BootContext $context): void
    {
        $settings = AdminSettings::fromConfig($context->config);
        if (! $settings->enabled) {
            return;
        }

        $container = $context->container;

        // Blade is required to render the dashboard and is NOT a dependency of this package — a JSON-only
        // deployment has no view factory. Mounting routes that would fatal on first request is worse than
        // mounting none, so back off silently and leave the JSON actuator as the management surface.
        if (! $container->bound('view')) {
            return;
        }

        $container->instance(AdminSettings::class, $settings);

        $container->singleton(AdminEndpointReader::class, static fn (): AdminEndpointReader => new AdminEndpointReader(
            $container->make(ActuatorRegistry::class),
            $context->config,
            $container,
        ));
        // The data browser is assembled here rather than declared as beans because it must exist even when
        // it is switched OFF: the dashboard asks it whether it is enabled, and a page that cannot ask has to
        // guess. Its own settings answer false by default, so building it costs a few objects and grants
        // nothing.
        // Assembled here for the same reason DataBrowser is: it must exist even when there is no database
        // manager to describe, because the page's job in that case is to say so.
        $container->singleton(DatasourceReport::class, static fn (): DatasourceReport => DatasourceReport::forContainer($container));
        $container->singleton(ConnectionWizard::class, static fn (): ConnectionWizard => ConnectionWizard::forContainer($container, $context->config));

        $container->singleton(DataBrowser::class, static function () use ($container, $context): DataBrowser {
            $settings = DataBrowserSettings::fromConfig($context->config);
            $introspector = new RepositoryIntrospector;

            return new DataBrowser(
                $settings,
                new DataResourceRegistry(
                    // Null when the actuator has not populated a catalogue — the registry treats that as
                    // "nothing discoverable" rather than failing the page.
                    $container->bound(BeansCatalog::class) ? $container->make(BeansCatalog::class) : null,
                    $introspector,
                    $settings,
                ),
                new DataSchemaFactory($introspector),
                new DataQueryEngine($introspector),
                $container,
            );
        });

        $container->singleton(AdminAction::class, static fn (): AdminAction => new AdminAction(
            $container->make(AdminSettings::class),
            $container->make(AdminEndpointReader::class),
            $container->make(ViewFactory::class),
            $container,
            // Resolved here rather than injected as a bean so the dashboard works whether or not the
            // actuator's own wiring has bound one: the settings come from the same config keys either way.
            new ManagementPortGuard(ManagementServerSettings::fromConfig($context->config)),
            $container->make(DataBrowser::class),
            $container->make(DatasourceReport::class),
            $container->make(SettingsConsole::class),
            $container->make(ConnectionWizard::class),
        ));

        /** @var Router $router */
        $router = $container->make('router');
        $base = $settings->basePath;

        $router->get($base, static fn (Request $request) => $container->make(AdminAction::class)($request))
            ->name('firefly.admin.index');
        $router->match(['GET', 'POST'], $base.'/{page}', static fn (Request $request, string $page) => $container->make(AdminAction::class)($request, $page))
            ->where('page', '[A-Za-z0-9\-_/]*')
            ->name('firefly.admin.page');
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use Composer\InstalledVersions;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Admin\AdminSettings;
use Firefly\Config\Config;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Scan\AppScan;
use Firefly\OpenApi\OpenApiProperties;
use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * The first page a new LaraFly application serves.
 *
 * #[Controller] is the HTML stereotype — Spring's @Controller to #[RestController]'s @RestController. It is
 * found by exactly the same route scan (it extends #[RestController]), but its methods return a view instead
 * of a value to negotiate into JSON.
 *
 * Nothing here is hard-coded. The boot phases are the real BootPhase enum, the bean and condition counts come
 * from the same objects /actuator/beans and /actuator/conditions serve, the routes come from the RouteManifest
 * the dispatcher itself reads, and the boot mode is determined by looking for the compiled manifest. Every
 * optional lookup is guarded, because this page must never be the reason a fresh application returns a 500.
 *
 * Delete this controller and resources/views/welcome.blade.php when you no longer need them — nothing else
 * refers to either.
 */
#[Controller]
final class WelcomeController
{
    public function __construct(
        private readonly RouteManifest $routes,
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    #[GetMapping('/', name: 'welcome')]
    public function index(): View
    {
        $base = trim($this->config->string('firefly.management.endpoints.web.base-path', '/actuator'), '/');

        return view('welcome', [
            'appName' => $this->config->string('app.name', 'LaraFly'),
            'environment' => $this->config->string('app.env', 'local'),
            'debug' => $this->config->bool('app.debug', false),
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => $this->packageVersion('laravel/framework'),
            'fireflyVersion' => $this->packageVersion('firefly/firefly'),
            'bootMode' => AppScan::cachedFile($this->container, AppScan::ROUTES) !== null ? 'compiled' : 'scanned',
            'phases' => $this->phases(),
            'beanCount' => $this->beanCount(),
            'conditions' => $this->conditions(),
            'actuatorBase' => '/'.$base,
            'exposed' => $this->exposed(),
            'endpoints' => $this->registeredEndpoints(),
            'routes' => $this->appRoutes($base),
            'tools' => $this->tools($base),
            'managementPort' => $this->managementPort(),
        ]);
    }

    /**
     * The port management traffic has been moved to, or null when it shares the application's port.
     *
     * This page must know, because when a management port IS configured the actuator and the dashboard stop
     * answering here — a card linking to them from the application port would link to a 404 and quietly
     * teach a developer that the feature is broken rather than that it moved.
     */
    private function managementPort(): ?int
    {
        if (! class_exists(\Firefly\Actuator\Server\ManagementServerSettings::class)) {
            return null;
        }

        return \Firefly\Actuator\Server\ManagementServerSettings::fromConfig($this->config)->port;
    }

    /**
     * The other surfaces this application is serving right now.
     *
     * Each is present only when its package is installed AND turned on, resolved from the same config keys
     * the packages themselves read — so the card never links to a 404. That matters more than it sounds: the
     * dashboard is off by default outside debug, and the API reference disappears when firefly/openapi is
     * not installed, so a hard-coded link would be wrong for most applications.
     *
     * @return list<array{href: string|null, label: string, blurb: string}>
     */
    private function tools(string $actuatorBase): array
    {
        // With a management port configured, the actuator and the dashboard answer only there — so they are
        // described rather than linked, and the page says where they went.
        $moved = $this->managementPort() !== null;

        $tools = [[
            'href' => $moved ? null : '/'.$actuatorBase,
            'label' => 'Actuator',
            'blurb' => 'Health, info and the endpoints you expose, as JSON.',
        ]];

        if (class_exists(AdminSettings::class)) {
            $admin = AdminSettings::fromConfig($this->config);
            if ($admin->enabled) {
                $tools[] = [
                    'href' => $moved ? null : $admin->url(),
                    'label' => 'Dashboard',
                    'blurb' => 'Health, beans, the bean graph, routes, metrics and configuration in the browser.',
                ];
            }
        }

        if ($this->config->bool('firefly.openapi.enabled', true) && class_exists(OpenApiProperties::class)) {
            if ($this->config->bool('firefly.openapi.viewer.enabled', true)) {
                $tools[] = [
                    'href' => '/'.trim($this->config->string('firefly.openapi.viewer.path', '/openapi'), '/'),
                    'label' => 'API reference',
                    'blurb' => 'Every endpoint, its schema and a request console — generated from your code.',
                ];
            }

            $tools[] = [
                'href' => '/'.trim($this->config->string('firefly.openapi.path', '/openapi.json'), '/'),
                'label' => 'OpenAPI document',
                'blurb' => 'The 3.1 spec, for a client generator or an API gateway.',
            ];
        }

        return $tools;
    }

    /**
     * The real boot pipeline. The ordinals are gapped on purpose so a later milestone can slot a phase
     * between two existing ones without renumbering — which is why they read 100, 200, … 650, 700.
     *
     * Each phase carries a short label for the rail and its exact enum case name for the tooltip: the
     * unabbreviated names ("AutoConfigurations") do not fit a rail cell without breaking mid-word, and a
     * broken word is harder to read than a shorter one.
     *
     * @return list<array{ordinal: int, label: string, name: string}>
     */
    private function phases(): array
    {
        $labels = [
            BootPhase::ConfigAndProfiles->name => 'Config & profiles',
            BootPhase::AutoConfigDiscovery->name => 'Discovery',
            BootPhase::UserConfigurations->name => 'Your beans',
            BootPhase::ConditionPassOne->name => 'Conditions I',
            BootPhase::AutoConfigurations->name => 'Auto-config',
            BootPhase::ConditionPassTwo->name => 'Conditions II',
            BootPhase::FlushDefinitions->name => 'Flush',
            BootPhase::BeanPostProcessors->name => 'Extenders',
            BootPhase::EventListeners->name => 'Listeners',
            BootPhase::InfrastructureStart->name => 'Infra start',
            BootPhase::EagerSingletons->name => 'Eager beans',
            BootPhase::WiringPasses->name => 'Wiring',
            BootPhase::ContextRefreshed->name => 'Refreshed',
        ];

        return array_map(
            static fn (BootPhase $phase): array => [
                'ordinal' => $phase->value,
                'label' => $labels[$phase->name] ?? $phase->name,
                'name' => $phase->name,
            ],
            BootPhase::cases(),
        );
    }

    /** @return array{matches: int, backedOff: int} */
    private function conditions(): array
    {
        $report = $this->optional(ConditionEvaluationReport::class);

        return $report instanceof ConditionEvaluationReport
            ? ['matches' => count($report->matches()), 'backedOff' => count($report->nonMatches())]
            : ['matches' => 0, 'backedOff' => 0];
    }

    private function beanCount(): int
    {
        $catalog = $this->optional(BeansCatalog::class);

        return $catalog instanceof BeansCatalog ? count($catalog->all()) : 0;
    }

    /** @return list<string> */
    private function registeredEndpoints(): array
    {
        $registry = $this->optional(ActuatorRegistry::class);

        return $registry instanceof ActuatorRegistry ? array_keys($registry->all()) : [];
    }

    /** @return list<string> */
    private function exposed(): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $this->config->string(
                'firefly.management.endpoints.web.exposure.include',
                'health,info',
            ))),
            static fn (string $id): bool => $id !== '',
        ));
    }

    /**
     * The application's own routes — the actuator's are excluded because they are the framework's, and
     * they are linked separately.
     *
     * @return list<array{method: string, path: string, controller: string, action: string}>
     */
    private function appRoutes(string $actuatorBase): array
    {
        $rows = array_map(
            static fn ($route): array => [
                'method' => $route->httpMethod,
                'path' => $route->path,
                'controller' => $route->controllerClass,
                'action' => $route->methodName,
            ],
            $this->routes->all(),
        );

        $rows = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $actuatorBase === '' || ! str_starts_with(ltrim($r['path'], '/'), $actuatorBase),
        ));

        usort($rows, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return $rows;
    }

    /**
     * A container lookup that never throws. This page is the first thing a new application serves; a missing
     * optional binding must degrade to a quieter page, not a 500.
     *
     * @param  class-string  $class
     */
    private function optional(string $class): ?object
    {
        try {
            return $this->container->bound($class) ? $this->container->get($class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function packageVersion(string $package): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                return InstalledVersions::getPrettyVersion($package) ?? 'dev';
            }
        } catch (Throwable) {
            // Fall through — a version string is decoration, never a reason to fail the page.
        }

        return 'dev';
    }
}

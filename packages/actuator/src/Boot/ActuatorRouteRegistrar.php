<?php

declare(strict_types=1);

namespace Firefly\Actuator\Boot;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Actuator\Web\ActuatorDispatchAction;
use Firefly\Actuator\Web\ActuatorIndexAction;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Mounts the actuator on the illuminate Router at phase WiringPasses (instance stage, like M6's RouteWiringPass) —
 * exactly two routes, both scoped under the base path so nothing collides with app controller routes or M6's
 * route-wiring (§7 risk 2). Also binds the request-time introspection snapshots BEFORE resolving endpoints (so a
 * BeansEndpoint/ConditionsEndpoint constructor can inject them), then resolves each discovered ActuatorEndpoint
 * bean once to populate ActuatorRegistry. The master gate firefly.management.enabled (default true) short-circuits
 * to registering nothing.
 */
final class ActuatorRouteRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 50;
    }

    public function run(BootContext $context): void
    {
        if (! $context->config->bool('firefly.management.enabled', true)) {
            return;
        }

        $container = $context->container;

        // (1) request-time introspection snapshots — bound FIRST so endpoint constructors can inject them.
        $container->instance(ConditionEvaluationReport::class, $context->report);
        $container->instance(BeansCatalog::class, $this->beansCatalog($context));

        // (2) populate the registry from the condition-filtered definitions (resolve each endpoint once).
        /** @var ActuatorRegistry $registry */
        $registry = $container->make(ActuatorRegistry::class);
        foreach ($context->definitions->all() as $definition) {
            if (is_a($definition->class(), ActuatorEndpoint::class, true)) {
                /** @var ActuatorEndpoint $endpoint */
                $endpoint = $container->make($definition->class());
                $registry->register($endpoint);
            }
        }

        // (3) two native routes under the base path.
        /** @var ExposureModel $exposure */
        $exposure = $container->make(ExposureModel::class);
        /** @var Router $router */
        $router = $container->make('router');
        $base = $exposure->basePath;

        $router->get($base, fn (Request $request) => $container->make(ActuatorIndexAction::class)($request))
            ->name('firefly.actuator.index');
        $router->match(['GET', 'POST'], $base.'/{path}', fn (Request $request, string $path) => $container->make(ActuatorDispatchAction::class)($request, $path))
            ->where('path', '.*')
            ->name('firefly.actuator.dispatch');
    }

    private function beansCatalog(BootContext $context): BeansCatalog
    {
        $beans = [];
        foreach ($context->definitions->all() as $definition) {
            $descriptor = $definition->descriptor;
            $beans[] = [
                'class' => $descriptor->class,
                'stereotype' => $descriptor->stereotype,
                'scope' => $descriptor->scope->name,
                'name' => $descriptor->name,
                'interfaces' => $descriptor->interfaces,
                'beans' => array_map(static fn ($bean): string => $bean->method, $descriptor->beans),
                // A #[Configuration]'s own edges are the union of its constructor's and every #[Bean]
                // factory method's parameters: that is where a framework's wiring actually lives, and a
                // graph built from constructors alone draws almost nothing.
                'produces' => array_map(static fn ($bean): array => [
                    'type' => $bean->returns,
                    'method' => $bean->method,
                    'dependencies' => $bean->dependencies,
                ], $descriptor->beans),
                // The bean graph's edges. Recorded by ComponentScanner at scan time — answering "what
                // depends on what" by reflecting at request time would break the reflection-free boot
                // contract, so the wiring is compiled like everything else.
                'dependencies' => $descriptor->dependencies,
            ];
        }

        return new BeansCatalog($beans);
    }
}

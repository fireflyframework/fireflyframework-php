<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Web\Route\RouteManifest;

/**
 * Lists the compiled route table (the M6 RouteManifest — the /mappings seam, a Spring-Boot-Actuator
 * MappingsEndpoint analog).
 *
 * Return type is narrowed to the non-nullable EndpointResponse (the same covariant-narrowing idiom
 * InfoEndpoint/EnvEndpoint/BeansEndpoint use): /mappings has no sub-resource concept to 404 on, so
 * handle() always produces a body — PHPStan (level max) flags the wider nullable type as dead code
 * otherwise.
 *
 * No #[Lazy] here: unlike BeansCatalog/ConditionEvaluationReport (only ever container-bound later,
 * at BootPhase::WiringPasses), RouteManifest is bound eagerly by WebServiceProvider::register()
 * (behind a bound() guard, defaulting to an empty manifest) — well before BootPhase::EagerSingletons
 * (900) runs. Verified directly against PackageBootTest: a plain (non-#[Lazy]) MappingsEndpoint does
 * not crash eager resolution.
 */
#[Component]
final class MappingsEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly RouteManifest $routes) {}

    public function endpointId(): string
    {
        return 'mappings';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $mappings = [];
        foreach ($this->routes->all() as $route) {
            $mappings[] = [
                'httpMethod' => $route->httpMethod,
                'path' => $route->path,
                'handler' => $route->controllerClass.'@'.$route->methodName,
                'name' => $route->name,
            ];
        }

        return EndpointResponse::json(['mappings' => $mappings]);
    }
}

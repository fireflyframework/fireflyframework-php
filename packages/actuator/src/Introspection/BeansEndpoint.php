<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;

/**
 * Lists the condition-filtered bean definitions captured at boot (BeansCatalog snapshot).
 *
 * Return type is narrowed to the non-nullable EndpointResponse (the same covariant-narrowing idiom
 * InfoEndpoint/EnvEndpoint use): /beans has no sub-resource concept to 404 on, so handle() always
 * produces a body — PHPStan (level max) flags the wider nullable type as dead code otherwise.
 *
 * #[Lazy] is REQUIRED here, not decorative: ActuatorRouteRegistrar (BootPhase::WiringPasses, 1000)
 * is the ONLY place BeansCatalog is ever container-bound (`$container->instance(BeansCatalog::class,
 * ...)`), and it does so BEFORE its own endpoint-registration loop resolves each ActuatorEndpoint.
 * But EagerSingletonsPass (BootPhase::EagerSingletons, 900) runs BEFORE WiringPasses and eagerly
 * resolves every non-#[Lazy] Scope::Singleton #[Component] straight from the manifest — including
 * this one, since it IS one. Without #[Lazy], EagerSingletonsPass tries to build BeansEndpoint at
 * phase 900, hits BeansCatalog (unbound, and its constructor takes a required `array $beans` with
 * no container-resolvable type), and throws BindingResolutionException, crashing boot entirely
 * (verified directly: this exact failure surfaced in the pre-existing PackageBootTest the moment
 * BeansEndpoint was added as a plain, non-#[Lazy] #[Component]). #[Lazy] excludes it from that eager
 * pass; ActuatorRouteRegistrar's own `$container->make($definition->class())` call at WiringPasses
 * still resolves it normally, now strictly after BeansCatalog is bound.
 */
#[Component]
#[Lazy]
final class BeansEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly BeansCatalog $catalog) {}

    public function endpointId(): string
    {
        return 'beans';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        return EndpointResponse::json(['beans' => $this->catalog->all()]);
    }
}

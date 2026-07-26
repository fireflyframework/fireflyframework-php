<?php

declare(strict_types=1);

namespace Firefly\Actuator\Info;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;

/**
 * The /info endpoint — deep-merges every InfoContributor fragment.
 *
 * Return type is narrowed to the non-nullable EndpointResponse (a legal covariant narrowing of
 * ActuatorEndpoint::handle()'s `?EndpointResponse`): unlike HealthEndpoint, /info has no sub-resource
 * concept to 404 on, so handle() always produces a body — PHPStan (level max) flags the wider nullable
 * type as dead code ("never returns null so it can be removed") if left as `?EndpointResponse`.
 *
 * #[Component] (T10 fix — a genuine gap, not a test bug): this class was never discoverable by
 * ActuatorRouteRegistrar without it, so /actuator/info was unreachable in every real boot; ZERO prior test
 * caught it because nothing exercised the endpoint at HTTP level before T10's capstone. No #[Lazy] needed:
 * InfoContributorRegistry is bound eagerly by ActuatorWiringProvider::register() (bound()-guarded), well
 * before BootPhase::EagerSingletons (900) runs.
 */
#[Component]
final class InfoEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly InfoContributorRegistry $registry) {}

    public function endpointId(): string
    {
        return 'info';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $info = [];
        foreach ($this->registry->all() as $contributor) {
            $info = array_replace_recursive($info, $contributor->info());
        }

        return EndpointResponse::json($info);
    }
}

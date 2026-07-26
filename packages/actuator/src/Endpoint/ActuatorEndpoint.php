<?php

declare(strict_types=1);

namespace Firefly\Actuator\Endpoint;

/**
 * A framework management endpoint. Framework endpoints are #[Component] beans (NOT app #[RestController]s):
 * ActuatorRouteRegistrar discovers them by this interface, resolves them once at boot, and mounts them under the
 * base path. handle() returns an EndpointResponse, or null to signal 404 (an unknown sub-resource).
 */
interface ActuatorEndpoint
{
    /** The stable id under the base path, e.g. 'health' → /actuator/health. */
    public function endpointId(): string;

    /** Per-endpoint kill switch; ActuatorDispatchAction also honours firefly.management.endpoint.{id}.enabled. */
    public function enabled(): bool;

    public function handle(EndpointRequest $request): ?EndpointResponse;
}

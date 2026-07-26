<?php

declare(strict_types=1);

namespace Firefly\Actuator\Endpoint;

/**
 * The id → endpoint map ActuatorRouteRegistrar populates at boot (resolving each discovered ActuatorEndpoint bean
 * once) and ActuatorDispatchAction/ActuatorIndexAction read at request time. A bound singleton, so both the boot
 * pass and the request actions share the one instance.
 */
final class ActuatorRegistry
{
    /** @var array<string, ActuatorEndpoint> */
    private array $endpoints = [];

    public function register(ActuatorEndpoint $endpoint): void
    {
        $this->endpoints[$endpoint->endpointId()] = $endpoint;
    }

    public function get(string $id): ?ActuatorEndpoint
    {
        return $this->endpoints[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->endpoints);
    }

    /**
     * @return array<string, ActuatorEndpoint>
     */
    public function all(): array
    {
        return $this->endpoints;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * One circuit breaker per endpoint (scheme://host:port), each a firefly/resilience CircuitBreaker over the bound
 * ResilienceStore — so its state is shared by every PHP-FPM worker and queue worker, exactly like an attribute
 * breaker's. Built directly rather than through ResilienceRegistry, whose instances are named in configuration;
 * endpoints are data. Keyed `firefly:callbacks:circuit-breaker:<sha1(endpoint)>`.
 */
final class EndpointCircuitBreakers
{
    /** @var array<string, CircuitBreaker> */
    private array $breakers = [];

    public function __construct(
        private readonly ResilienceStore $store,
        private readonly int $failureThreshold = 5,
        private readonly float $waitDurationInOpen = 30.0,
    ) {}

    public function for(string $endpoint): CircuitBreaker
    {
        return $this->breakers[$endpoint] ??= new CircuitBreaker(
            key: 'firefly:callbacks:circuit-breaker:'.sha1($endpoint),
            store: $this->store,
            failureThreshold: $this->failureThreshold,
            windowSize: max(10, $this->failureThreshold),
            waitDurationInOpen: $this->waitDurationInOpen,
        );
    }
}

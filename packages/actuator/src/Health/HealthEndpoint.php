<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Throwable;

/**
 * The /health endpoint. No subPath → aggregate every indicator; subPath [group] → aggregate only that probe group's
 * members (firefly.management.endpoint.health.group.{name}.include, CSV) — an unknown group returns null (404).
 * Each indicator runs FAIL-SAFE: a thrown health() becomes DOWN with an error detail, never an unhandled 500.
 * show-details (never|when-authorized|always, default never) governs whether per-component details are emitted;
 * when-authorized degrades to never here (actuator has NO code edge to Security — auth-gating the details is a
 * config/lockdown concern, documented in Task 11). The HTTP status is Status::httpStatus() (DOWN/OUT_OF_SERVICE → 503).
 *
 * #[Component] (T10 fix — a genuine gap, not a test bug): this class was never discoverable by
 * ActuatorRouteRegistrar without it, so /actuator/health — actuator's flagship endpoint — was unreachable in
 * every real boot; ZERO prior test caught it because nothing exercised the endpoint at HTTP level before T10's
 * capstone. No #[Lazy] needed: HealthContributorRegistry/StatusAggregator are both bound eagerly by
 * ActuatorWiringProvider::register() (bound()-guarded), well before BootPhase::EagerSingletons (900) runs —
 * the same reasoning MappingsEndpoint's own docblock gives for RouteManifest.
 */
#[Component]
final class HealthEndpoint implements ActuatorEndpoint
{
    public function __construct(
        private readonly HealthContributorRegistry $registry,
        private readonly StatusAggregator $aggregator,
        private readonly Config $config,
    ) {}

    public function endpointId(): string
    {
        return 'health';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        $indicators = $this->registry->all();

        if ($request->subPath !== []) {
            $members = $this->group($request->subPath[0]);
            if ($members === null) {
                return null; // unknown group → 404
            }
            $indicators = array_intersect_key($indicators, array_flip($members));
        }

        $components = [];
        $statuses = [];
        foreach ($indicators as $name => $indicator) {
            $health = $this->readFailSafe($indicator);
            $statuses[] = $health->status;
            $components[$name] = ['status' => $health->status->value, 'details' => $health->details];
        }

        $status = $this->aggregator->aggregate($statuses);
        $body = ['status' => $status->value];

        if ($this->showDetails()) {
            $body['components'] = $components;
        }

        return EndpointResponse::json($body, $status->httpStatus());
    }

    private function readFailSafe(HealthIndicator $indicator): Health
    {
        try {
            return $indicator->health();
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }
    }

    /**
     * @return list<string>|null null → the group is not configured
     */
    private function group(string $name): ?array
    {
        $key = "firefly.management.endpoint.health.group.{$name}.include";
        if (! $this->config->has($key)) {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->config->string($key))),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    private function showDetails(): bool
    {
        return $this->config->string('firefly.management.endpoint.health.show-details', 'never') === 'always';
    }
}

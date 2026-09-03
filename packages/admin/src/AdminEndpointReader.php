<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Config\Config;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Reads an ActuatorEndpoint's payload in-process — the same bean the JSON surface serves, invoked directly
 * rather than over HTTP.
 *
 * Doing it in-process is what makes the dashboard useful. ExposureModel defaults to `health,info`, so
 * fetching /actuator/beans over HTTP would 404 unless the application first published beans, conditions and
 * env to every caller. The dashboard needs none of that: it holds the registry, so it can render what the
 * process already knows while the JSON surface stays secure-by-default. AdminSettings carries the
 * corresponding access decision.
 *
 * A per-endpoint kill switch is still honoured (`firefly.management.endpoint.{id}.enabled`), because that
 * key means "this endpoint is off", not "this endpoint is unpublished".
 */
final readonly class AdminEndpointReader
{
    public function __construct(
        private ActuatorRegistry $registry,
        private Config $config,
        private ?Container $container = null,
    ) {}

    /**
     * Every health indicator with its own status and details, read from the CONTRIBUTOR REGISTRY rather than
     * through the health endpoint.
     *
     * The endpoint withholds per-indicator details unless
     * `firefly.management.endpoint.health.show-details` is `always`, and that default is right: it protects
     * anonymous HTTP callers from learning your database host from a failed connection. The dashboard is not
     * an anonymous HTTP caller — it is already rendering beans and env in-process — so applying the HTTP
     * disclosure policy to it produced a Health panel whose entire content was an apology telling the
     * operator to go and change a config key. It reads the indicators directly instead.
     *
     * Each indicator is called in isolation: one that throws is reported DOWN with the reason, exactly as
     * HealthEndpoint's own fail-safe read does, so a broken indicator degrades its own row and nothing else.
     *
     * @return list<array{name: string, status: string, details: array<string, mixed>}>
     */
    public function healthIndicators(): array
    {
        if ($this->container === null || ! $this->container->bound(HealthContributorRegistry::class)) {
            return [];
        }

        try {
            $registry = $this->container->get(HealthContributorRegistry::class);
        } catch (Throwable) {
            return [];
        }

        $indicators = [];
        foreach ($registry->all() as $name => $indicator) {
            try {
                $health = $indicator->health();
                $indicators[] = [
                    'name' => $name,
                    'status' => $health->status->value,
                    'details' => $health->details,
                ];
            } catch (Throwable $e) {
                $indicators[] = [
                    'name' => $name,
                    'status' => 'DOWN',
                    'details' => ['error' => $e::class, 'message' => $e->getMessage()],
                ];
            }
        }

        return $indicators;
    }

    /**
     * The endpoint ids that are registered AND not switched off, in registration order.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $ids = [];
        foreach ($this->registry->all() as $id => $endpoint) {
            if ($endpoint->enabled() && $this->config->bool("firefly.management.endpoint.{$id}.enabled", true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function has(string $id): bool
    {
        return in_array($id, $this->available(), true);
    }

    /**
     * The endpoint's payload as an array, or null when it is absent, switched off, returned no body, or
     * threw.
     *
     * A throwing endpoint must not take the page down with it: one broken health indicator should degrade
     * that panel, not the dashboard. The failure is surfaced to the caller as null so the view can say so.
     *
     * @param  list<string>  $subPath
     * @param  array<string,mixed>  $query
     * @return array<mixed>|null
     */
    public function read(string $id, array $subPath = [], array $query = []): ?array
    {
        $endpoint = $this->registry->get($id);
        if ($endpoint === null || ! $this->has($id)) {
            return null;
        }

        try {
            $response = $endpoint->handle(new EndpointRequest('GET', $subPath, $query));
        } catch (Throwable) {
            return null;
        }

        return $response === null || is_string($response->body) ? null : $response->body;
    }

    /**
     * POST to an endpoint — the loggers endpoint's level mutation is the only current caller.
     *
     * @param  list<string>  $subPath
     * @param  array<string,mixed>  $body
     */
    public function write(string $id, array $subPath, array $body): bool
    {
        $endpoint = $this->registry->get($id);
        if ($endpoint === null || ! $this->has($id)) {
            return false;
        }

        try {
            $response = $endpoint->handle(new EndpointRequest('POST', $subPath, [], $body));
        } catch (Throwable) {
            return false;
        }

        return $response !== null && $response->status >= 200 && $response->status < 300;
    }
}

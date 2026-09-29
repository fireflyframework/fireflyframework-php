<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support\Fixtures;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;

/**
 * A `metrics` endpoint answering with a fixed set of meters, in the two shapes the real one answers in.
 *
 * REGISTERED INTO THE REAL REGISTRY, so the Metrics page is rendered by the dashboard's own pipeline — the
 * reader resolves it, AdminAction reads the index and then reads each name back, and the view renders what
 * came out. What is stubbed is the METER REGISTRY behind it, and that is stubbed for the reason
 * AdminOAuth2PageProcessLocalTest stubs its endpoint: a testbench that boots no observability stack has no
 * meters at all, and `/firefly/metrics` would render its empty state — a branch that proves the empty state
 * and nothing about a colgroup, a sort link or a pager.
 *
 * The two shapes are MetricsEndpoint's own, verified against it: no subPath answers `{names: [...]}` with the
 * names sorted and deduplicated, and `[name]` answers `{name, measurements, availableTags}` or null — a 404
 * — for a name the registry does not hold. A meter with no measurements is legal and is here on purpose:
 * several meters may share a name, so the list is per-name rather than per-meter, and the Metrics view has a
 * whole `@empty` arm for the case where it comes back empty.
 */
final readonly class MetricsEndpointStub implements ActuatorEndpoint
{
    /**
     * @param  array<string, list<array{statistic: string, value: float}>>  $meters  meter name => measurements
     */
    public function __construct(private array $meters) {}

    public function endpointId(): string
    {
        return 'metrics';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        if ($request->subPath === []) {
            $names = array_keys($this->meters);
            sort($names);

            return EndpointResponse::json(['names' => $names]);
        }

        $name = $request->subPath[0];
        if (! array_key_exists($name, $this->meters)) {
            return null;
        }

        return EndpointResponse::json([
            'name' => $name,
            'measurements' => $this->meters[$name],
            'availableTags' => [],
        ]);
    }
}

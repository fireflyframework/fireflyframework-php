<?php

declare(strict_types=1);

namespace Firefly\Observability\Endpoint;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Observability\Metrics\Counter;
use Firefly\Observability\Metrics\Gauge;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Metrics\Timer;

/**
 * The Micrometer-JSON /metrics endpoint. No subPath → {"names":[...]} (sorted unique meter names); subPath [name] →
 * a single metric's measurements + available tags (null → 404). Gated on firefly.observability.metrics.enabled (§7
 * risk 1) — the SAME property that gates the MeterRegistry bean itself, not MeterRegistry's presence (see
 * PrometheusEndpoint's docblock for why #[ConditionalOnBean] would be order-unsafe here).
 *
 * #[Lazy] is REQUIRED, not decorative — same eager-resolution hazard as PrometheusEndpoint: MeterRegistry is a
 * #[Bean] resolved at BootPhase::EagerSingletons (900), the same phase this #[Component] would otherwise be
 * eagerly built at. #[Lazy] defers construction to ActuatorRouteRegistrar's own resolve at
 * BootPhase::WiringPasses (1000), strictly after MeterRegistry is bound.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class MetricsEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly MeterRegistry $registry) {}

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
            $names = array_values(array_unique(array_map(static fn ($m): string => $m->name(), $this->registry->meters())));
            sort($names);

            return EndpointResponse::json(['names' => $names]);
        }

        $name = $request->subPath[0];
        $measurements = [];
        $tagValues = [];
        $found = false;

        foreach ($this->registry->meters() as $meter) {
            if ($meter->name() !== $name) {
                continue;
            }
            $found = true;
            $measurements[] = $this->measurement($meter);
            foreach ($meter->tags() as $tag => $value) {
                $tagValues[$tag][$value] = true;
            }
        }

        if (! $found) {
            return null;
        }

        $availableTags = [];
        foreach ($tagValues as $tag => $values) {
            $availableTags[] = ['tag' => $tag, 'values' => array_keys($values)];
        }

        return EndpointResponse::json(['name' => $name, 'measurements' => $measurements, 'availableTags' => $availableTags]);
    }

    /**
     * @return array{statistic: string, value: float}
     */
    private function measurement(object $meter): array
    {
        return match (true) {
            $meter instanceof Counter => ['statistic' => 'COUNT', 'value' => $meter->count()],
            $meter instanceof Timer => ['statistic' => 'TOTAL_TIME', 'value' => $meter->totalTimeSeconds()],
            $meter instanceof Gauge => ['statistic' => 'VALUE', 'value' => $meter->value()],
            default => ['statistic' => 'VALUE', 'value' => 0.0],
        };
    }
}

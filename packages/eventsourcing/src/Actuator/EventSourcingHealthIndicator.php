<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Actuator;

use Firefly\Actuator\Health\ConditionalHealthIndicator;
use Firefly\Actuator\Health\Health;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Container as FireflyContainer;
use Firefly\EventSourcing\EventSourcingSettings;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Store\EventStore;
use Throwable;

/**
 * The `eventsourcing` health component (HealthContributorRegistrar names it after the class). DOWN when the event
 * store does not answer, or when a catch-up projection lags the global head by more than
 * firefly.eventsourcing.projection.max-lag events (0 = never). UP otherwise, with the head and every projection's
 * position, lag and state. A failed projection is reported in the details but does not turn the component DOWN by
 * itself: its growing lag is what trips the threshold. Inline projections never lag. Absent while
 * firefly.eventsourcing.health.enabled is false.
 */
#[Component]
final class EventSourcingHealthIndicator implements ConditionalHealthIndicator
{
    public function __construct(private readonly FireflyContainer $beans) {}

    public function available(): bool
    {
        if (! $this->beans->has(EventSourcingSettings::class)) {
            return false;
        }

        $settings = $this->beans->get(EventSourcingSettings::class);

        return $settings instanceof EventSourcingSettings && $settings->healthEnabled;
    }

    public function health(): Health
    {
        /** @var EventSourcingSettings $settings */
        $settings = $this->beans->get(EventSourcingSettings::class);
        /** @var EventStore $store */
        $store = $this->beans->get(EventStore::class);

        if (! $store->isHealthy()) {
            return Health::down(['store' => $settings->storeProvider, 'error' => 'the event store does not answer']);
        }

        try {
            /** @var ProjectionRunner $runner */
            $runner = $this->beans->get(ProjectionRunner::class);
            $statuses = $runner->statuses();
            $projections = [];
            $lagging = [];
            foreach ($statuses as $status) {
                $detail = ['position' => $status->position, 'lag' => $status->lag, 'status' => $status->state()];
                if ($status->lastError !== null) {
                    $detail['error'] = $status->lastError;
                }
                $projections[$status->name] = $detail;
                if (! $status->inline && $settings->maxLag > 0 && $status->lag > $settings->maxLag) {
                    $lagging[] = "{$status->name} ({$status->lag})";
                }
            }

            $details = [
                'store' => $settings->storeProvider,
                'position' => $statuses === [] ? $store->currentPosition() : $statuses[0]->head,
                'projections' => $projections,
            ];

            if ($lagging !== []) {
                return Health::down($details + [
                    'reason' => "projections lag more than firefly.eventsourcing.projection.max-lag ({$settings->maxLag} events): ".implode(', ', $lagging),
                ]);
            }

            return Health::up($details);
        } catch (Throwable $error) {
            return Health::down(['store' => $settings->storeProvider, 'error' => $error->getMessage()]);
        }
    }
}

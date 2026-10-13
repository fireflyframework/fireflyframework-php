<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

/**
 * Where lifecycle events go. `firefly.orchestration.events.enabled=false` (the default) wires the no-op; true
 * wires EdaOrchestrationEventPublisher over the application's firefly/eda EventPublisher.
 */
interface OrchestrationEventPublisher
{
    public function publish(OrchestrationEvent $event): void;
}

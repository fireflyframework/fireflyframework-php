<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

/** Publishes nothing: lifecycle events are off (firefly.orchestration.events.enabled=false). */
final class NoOpOrchestrationEventPublisher implements OrchestrationEventPublisher
{
    public function publish(OrchestrationEvent $event): void {}
}

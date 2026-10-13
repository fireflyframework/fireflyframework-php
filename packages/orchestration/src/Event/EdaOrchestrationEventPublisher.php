<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Eda\EventPublisher;

/**
 * Publishes OrchestrationEvents through firefly/eda, so they reach whatever broker the application configured
 * (memory, queue, the database outbox, RabbitMQ, Kafka). The destination is the event's topic, else
 * `firefly.orchestration.events.default-topic`, else `orchestration`; the event type is the event's own type,
 * else `orchestration.<event_type>` (`orchestration.execution_completed`); headers carry the correlation id,
 * the pattern and, for keyed step events, the key a partitioned broker routes by.
 */
final class EdaOrchestrationEventPublisher implements OrchestrationEventPublisher
{
    public function __construct(
        private readonly EventPublisher $publisher,
        private readonly string $defaultTopic = '',
    ) {}

    public function publish(OrchestrationEvent $event): void
    {
        $headers = ['correlationId' => $event->correlationId, 'pattern' => $event->pattern->value];
        if ($event->key !== null) {
            $headers['key'] = $event->key;
        }

        $this->publisher->publish(
            $event->topic ?? ($this->defaultTopic !== '' ? $this->defaultTopic : 'orchestration'),
            $event->type ?? 'orchestration.'.strtolower($event->eventType),
            $event->toArray(),
            $headers,
        );
    }
}

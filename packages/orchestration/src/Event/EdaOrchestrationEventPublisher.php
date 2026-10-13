<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Eda\EventPublisher;

/**
 * Publishes OrchestrationEvents through firefly/eda, so they reach whatever broker the application configured
 * (memory, queue, the database outbox, RabbitMQ, Kafka). The destination is the event's topic, else
 * `firefly.orchestration.events.default-topic`, else `orchestration`; the event type is the event's own type,
 * else `orchestration.<event_type>` (`orchestration.execution_completed`).
 *
 * Headers follow the framework's EDA convention, the one EdaCommandEventPublisher stamps and the transports
 * read: `x-correlation-id` carries the correlation id (the Postgres outbox takes its transaction id from it,
 * and Kafka partitions by it when no key is set), and a keyed step event adds `partition_key`, which Kafka
 * routes by first, so every event for one key lands on one partition in order. The `correlationId`, `key`
 * and `pattern` headers ride alongside (jafly parity).
 */
final class EdaOrchestrationEventPublisher implements OrchestrationEventPublisher
{
    public function __construct(
        private readonly EventPublisher $publisher,
        private readonly string $defaultTopic = '',
    ) {}

    public function publish(OrchestrationEvent $event): void
    {
        $headers = [
            'x-correlation-id' => $event->correlationId,
            'correlationId' => $event->correlationId,
            'pattern' => $event->pattern->value,
        ];
        if ($event->key !== null) {
            $headers['partition_key'] = $event->key;
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

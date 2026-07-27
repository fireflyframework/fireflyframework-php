<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Fixtures;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use RuntimeException;

/**
 * A DISTINCT in-memory downstream EventPublisher (a stand-in for a real Kafka/RabbitMQ publisher) used by the
 * OutboxRelay tests: it records every publish and, when $fail is set, throws to exercise the retry/FAILED path. It is
 * intentionally NOT a PostgresEventPublisher — that is the whole point of B1 (the relay's downstream must be a
 * distinct broker, never the outbox writer itself).
 */
final class SpyDownstreamPublisher implements EventPublisher
{
    /** @var list<EventEnvelope> */
    public array $published = [];

    public bool $fail = false;

    public function subscribe(string $eventTypePattern, callable $handler): void {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('downstream down');
        }
        $this->published[] = new EventEnvelope($eventType, $destination, $payload, $headers);
    }

    public function start(): void {}

    public function stop(): void {}
}

<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Fixtures;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use RuntimeException;

/**
 * A DISTINCT in-memory downstream EventPublisher (a stand-in for a real Kafka/RabbitMQ publisher) used by the
 * OutboxRelay tests: it records every publish and, when $fail is set, throws to exercise the retry/FAILED path. It is
 * intentionally NOT a PostgresEventPublisher — that is the whole point of B1 (the relay's downstream must be a
 * distinct broker, never the outbox writer itself).
 *
 * It publishes through the EdaTracing seam exactly as the three real adapters do, because on the relay hop that
 * detail IS the behaviour under test: tracePublish() stamps its own traceparent LAST, so a spy that merely
 * recorded the headers it was handed would report a trace continuity the real downstream never had. Left null it
 * is the NoOp, and this class is byte-for-byte the recorder it has always been.
 */
final class SpyDownstreamPublisher implements EventPublisher
{
    /** @var list<EventEnvelope> */
    public array $published = [];

    public bool $fail = false;

    private readonly EdaTracing $tracing;

    public function __construct(?EdaTracing $tracing = null)
    {
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

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

        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $this->published[] = new EventEnvelope($eventType, $destination, $payload, $headers);
        });
    }

    public function start(): void {}

    public function stop(): void {}
}

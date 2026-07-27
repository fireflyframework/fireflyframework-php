<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The Postgres EventPublisher: publish() INSERTs ONE firefly_eda_outbox row ON THE CURRENT CONNECTION. When called
 * inside a DB transaction (which M8's #[Transactional] and the OutboxPreCommitHook guarantee for domain events), the
 * INSERT enlists in that transaction and commits ATOMICALLY with the aggregate — the genuine same-tx guarantee. When
 * emitNotify is true (pgsql), it also fires `SELECT pg_notify('<channel>', id)` — inside the SAME tx, so Postgres
 * queues the NOTIFY and delivers it exactly when the aggregate COMMITS, waking the in-process consumer's LISTEN with
 * low latency (M2). The in-process consumer (Task 5) claims PENDING rows and drives #[EventListener] handlers; the
 * OPTIONAL firefly:outbox:relay only fronts a distinct downstream broker. subscribe() feeds the shared
 * SubscriberRegistry; start()/stop() are no-ops (the relay/consumer own their own connections). It uses only
 * ConnectionInterface methods (table()/statement()/raw()) — no getPdo()/getDriverName() — so ConnectionInterface is
 * the correct ctor type; the driver-gated emitNotify flag is computed by the caller from the concrete Connection.
 * Reflection-free.
 */
final class PostgresEventPublisher implements EventPublisher
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $channel = 'firefly_eda_events',
        private readonly bool $emitNotify = false,
    ) {}

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        // No-op on the publisher: subscriptions live on the shared SubscriberRegistry the consumer drives.
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $id = $this->connection->table(OutboxSchema::TABLE)->insertGetId([
            'destination' => $destination,
            'channel' => $this->channel,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($headers === [] ? new stdClass : $headers, JSON_THROW_ON_ERROR),
            'transaction_id' => $headers['x-correlation-id'] ?? null,
            'status' => OutboxSchema::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
        ]);

        if ($this->emitNotify) {
            // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly
            // when the PENDING row becomes visible. statement() is on ConnectionInterface. (sqlite: emitNotify=false.)
            $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
        }
    }

    public function start(): void {}

    public function stop(): void {}
}

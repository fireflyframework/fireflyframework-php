<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\Outbox\OutboxRow;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\Pgsql\NotificationWaiter;
use Illuminate\Database\Connection;

/**
 * The TERMINAL in-process delivery path (B1): drives #[EventListener] handlers from committed outbox rows with NO
 * EventPublisher call, so there is no re-INSERT / self-reference. subscribe() issues LISTEN <channel>; poll() waits on
 * a NOTIFY (NotificationWaiter, pgsql only — the writer's in-tx pg_notify fires on commit) up to $timeoutMs, then
 * CLAIMS the oldest status='PENDING' row (FOR UPDATE SKIP LOCKED on pgsql; plain ORDER BY id on sqlite) and returns it
 * as an envelope (a poll fallback also catches rows missed while offline). ConsumerLoop delivers it to the
 * SubscriberRegistry and then ack()s -> the row is marked PUBLISHED/processed_at (guarded WHERE status='PENDING', so
 * idempotent); on a handler throw ConsumerLoop nack()s -> attempts+1, or FAILED/failed_at past max_attempts. This is a
 * DURABLE PENDING->PUBLISHED status window (M3), NOT an in-memory high-water mark: a restart resumes at the oldest
 * still-PENDING row and never replays PUBLISHED rows. SINGLE consumer worker gives exactly-once in-process delivery;
 * multi-worker degrades to at-least-once (idempotent handlers documented). Requires the concrete Illuminate\Database\
 * Connection because getPdo()/getDriverName() are not on ConnectionInterface (B2). NOT final: a test-only fixture
 * subclass overrides the protected awaitNotification() seam to simulate a deprecation-to-exception handler without a
 * live pgsql socket (see PostgresEventConsumerTest + Fixtures/ThrowingNotificationConsumer).
 */
class PostgresEventConsumer implements EventConsumer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $channel = 'firefly_eda_events',
        private readonly int $maxAttempts = 3,
    ) {}

    public function subscribe(array $destinations): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->getPdo()->exec('LISTEN '.$this->channel);
        }
    }

    public function start(): void {}

    /**
     * Blocks up to $timeoutMs for a Postgres NOTIFY (pgsql only). The NOTIFY wake is a pure low-latency optimization —
     * poll() always falls through to the durable PENDING-row claim — so poll() guards this against ANY failure (e.g.
     * an app that converts the deprecated pgsqlGetNotify() E_DEPRECATED into an exception) and continues to the claim.
     * protected so a test can substitute a throwing waiter without a live pgsql socket.
     */
    protected function awaitNotification(int $timeoutMs): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            NotificationWaiter::wait($this->connection->getPdo(), $timeoutMs);
        }
    }

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        try {
            $this->awaitNotification($timeoutMs);
        } catch (\Throwable) {
            // NOTIFY is a low-latency optimization; on ANY failure (incl. a deprecation-to-exception handler on the
            // deprecated pgsqlGetNotify path) fall through to the poll-fallback claim below, which delivers regardless.
        }

        $pgsql = $this->connection->getDriverName() === 'pgsql';
        $query = $this->connection->table(OutboxSchema::TABLE)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->orderBy('id');
        if ($pgsql) {
            $query->lock('for update skip locked'); // claim (M4) — Laravel has no skipLocked() helper
        }
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return new ReceivedEnvelope(new EventEnvelope(
            OutboxRow::asString($row->event_type),
            OutboxRow::asString($row->destination),
            OutboxRow::asMap($row->payload),
            OutboxRow::asStringMap($row->headers),
        ), OutboxRow::asInt($row->id));
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->connection->table(OutboxSchema::TABLE)
            ->where('id', $received->deliveryTag)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->update([
                'status' => OutboxSchema::STATUS_PUBLISHED,
                'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
            ]);
    }

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void
    {
        $row = $this->connection->table(OutboxSchema::TABLE)->where('id', $received->deliveryTag)->first();
        if ($row === null) {
            return;
        }
        $attempts = OutboxRow::asInt($row->attempts) + 1;
        $failed = $attempts >= $this->maxAttempts;
        $this->connection->table(OutboxSchema::TABLE)->where('id', $received->deliveryTag)->update([
            'attempts' => $attempts,
            'status' => $failed ? OutboxSchema::STATUS_FAILED : OutboxSchema::STATUS_PENDING,
            'error_message' => 'in-process handler failed',
            'failed_at' => $failed ? $this->connection->raw('CURRENT_TIMESTAMP') : null,
        ]);
    }

    public function stop(): void {}
}

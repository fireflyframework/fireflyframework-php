<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

/**
 * The OPTIONAL store-and-forward relay: claims committed PENDING outbox rows and publishes them to a DISTINCT
 * downstream broker (a Kafka/RabbitMQ EventPublisher), marking each PUBLISHED or (past maxAttempts) FAILED. It NEVER
 * accepts a PostgresEventPublisher downstream — that would re-INSERT PENDING rows into the same outbox (infinite loop),
 * so the ctor guards with a LogicException (B1). Terminal in-process delivery is NOT this class's job — PostgresEventConsumer
 * owns it. On Postgres the claim uses `FOR UPDATE SKIP LOCKED` (via ->lock('for update skip locked') — the installed
 * Laravel Builder has no skipLocked() helper) inside a short tx so concurrent relay workers never double-claim; on
 * sqlite (unit tests) useSkipLocked=false ⇒ a plain SELECT + per-row UPDATE guard (`WHERE id=? AND status='PENDING'`)
 * gives the same at-least-once dedup single-threaded. The claim/mark is the idempotency: a PUBLISHED row is never
 * re-selected. A single relay worker gives effective FIFO (ORDER BY id). The caller passes useSkipLocked (computed from
 * the concrete Connection's driver) so this class needs only ConnectionInterface (table/raw/transaction).
 */
final class OutboxRelay
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EventPublisher $downstream,
        private readonly int $batchSize = 50,
        private readonly int $maxAttempts = 3,
        private readonly bool $useSkipLocked = false,
    ) {
        if ($downstream instanceof PostgresEventPublisher) {
            throw new LogicException('OutboxRelay downstream must NOT be a PostgresEventPublisher — it would re-insert PENDING rows into the same outbox (infinite loop). Configure firefly.eda.postgres.relay.downstream_provider=rabbitmq|kafka.');
        }
    }

    public function relayBatch(): int
    {
        if ($this->useSkipLocked) {
            /** @var int $relayed */
            $relayed = $this->connection->transaction(fn (): int => $this->processBatch($this->claim()));

            return $relayed;
        }

        return $this->processBatch($this->claim());
    }

    /** @return Collection<int, \stdClass> */
    private function claim(): Collection
    {
        $query = $this->connection->table(OutboxSchema::TABLE)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->orderBy('id')
            ->limit($this->batchSize);

        if ($this->useSkipLocked) {
            $query->lock('for update skip locked'); // pgsql/MySQL raw lock — Laravel has no skipLocked() builder method
        }

        /** @var Collection<int, \stdClass> $rows */
        $rows = $query->get();

        return $rows;
    }

    /** @param Collection<int, \stdClass> $rows */
    private function processBatch(Collection $rows): int
    {
        $relayed = 0;
        foreach ($rows as $row) {
            try {
                $this->downstream->publish(
                    OutboxRow::asString($row->destination),
                    OutboxRow::asString($row->event_type),
                    OutboxRow::asMap($row->payload),
                    OutboxRow::asStringMap($row->headers),
                );

                $this->connection->table(OutboxSchema::TABLE)
                    ->where('id', $row->id)->where('status', OutboxSchema::STATUS_PENDING)
                    ->update(['status' => OutboxSchema::STATUS_PUBLISHED, 'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP')]);
                $relayed++;
            } catch (Throwable $e) {
                $attempts = OutboxRow::asInt($row->attempts) + 1;
                $failed = $attempts >= $this->maxAttempts;
                $this->connection->table(OutboxSchema::TABLE)->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'status' => $failed ? OutboxSchema::STATUS_FAILED : OutboxSchema::STATUS_PENDING,
                    'error_message' => $e->getMessage(),
                    'failed_at' => $failed ? $this->connection->raw('CURRENT_TIMESTAMP') : null,
                ]);
            }
        }

        return $relayed;
    }
}

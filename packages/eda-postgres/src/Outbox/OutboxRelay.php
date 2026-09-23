<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
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
 *
 * THE RELAY HOP IS A CONTINUATION, NOT A NEW TRACE. A claimed row already carries the `traceparent` the producer
 * span stamped on it when it was INSERTed in the aggregate's transaction. Forwarding those headers straight to
 * $downstream->publish() destroyed it: the downstream adapter publishes through EdaTracing::tracePublish(), which
 * starts its span with no explicit parent — and on a relay worker there is no current span, so it started a fresh
 * ROOT — then merges its own injected headers LAST, overwriting the row's traceparent with an unrelated one. A row
 * published under T1 reached the broker under T2 and the consumer on the far side continued the wrong trace: the
 * same "trace of half a system" the producer spans were added to fix, reintroduced one hop later. So the forward
 * runs inside traceConsume() of an envelope rebuilt from the row — the identical rule SubscriberRegistrySink
 * applies on the in-process path — which makes the row's traceparent the CONSUMER span's remote parent and the
 * downstream producer span that span's child. The seam is the UNGATED bound EdaTracing: traceConsume writes
 * nothing on any wire, so firefly.eda.tracing.brokers.enabled has no business silencing it — that key gates the
 * downstream PUBLISHER, which RelayDownstream builds (see its parameters()).
 */
final class OutboxRelay
{
    private readonly EdaTracing $tracing;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EventPublisher $downstream,
        private readonly int $batchSize = 50,
        private readonly int $maxAttempts = 3,
        private readonly bool $useSkipLocked = false,
        ?EdaTracing $tracing = null,
    ) {
        $this->tracing = $tracing ?? new NoOpEdaTracing;

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
                // The envelope is rebuilt ONLY so the seam has a traceparent to continue and a message id to name
                // the span with — the downstream still receives the row's own four columns, byte for byte.
                $this->tracing->traceConsume(
                    new EventEnvelope(
                        OutboxRow::asString($row->event_type),
                        OutboxRow::asString($row->destination),
                        OutboxRow::asMap($row->payload),
                        OutboxRow::asStringMap($row->headers),
                        OutboxRow::asString($row->id),
                    ),
                    fn (EventEnvelope $claimed) => $this->downstream->publish(
                        $claimed->destination,
                        $claimed->eventType,
                        $claimed->payload,
                        $claimed->headers,
                    ),
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

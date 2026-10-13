<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox;

use Closure;
use DateTimeImmutable;
use Firefly\Eda\Database\Outbox\Claim\ClaimableRows;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategy;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Database\Connection;
use LogicException;
use Throwable;

/**
 * The OPTIONAL store-and-forward relay behind firefly:outbox:relay: claims a batch of committed PENDING rows, forwards
 * each to a DISTINCT downstream broker (a Kafka/RabbitMQ EventPublisher), and marks it PUBLISHED — or, on a forward
 * failure, counts the attempt and marks it FAILED past maxAttempts. Terminal in-process delivery is not its job
 * (DatabaseEventConsumer owns that).
 *
 * THE CLAIM IS A LEASE, the same one the consumer takes (see OutboxClaimStrategy): the batch is leased to this relay
 * and committed before the first forward, the forwards run outside any claim transaction, and each row is settled only
 * while this relay still holds it — so any number of relay workers can drain one outbox on any driver, and a relay that
 * outlived its lease settles nothing (relayBatch() counts only rows it settled).
 *
 * It NEVER accepts an OutboxWriter downstream: forwarding through the outbox writer would re-INSERT every claimed row as
 * PENDING into the table being drained. And THE RELAY HOP IS A CONTINUATION, NOT A NEW TRACE: the forward runs inside
 * traceConsume() of an envelope rebuilt from the row, so the row's `traceparent` becomes the consumer span's remote
 * parent and the downstream producer span its child (the seam is the UNGATED bound EdaTracing — traceConsume writes
 * nothing on any wire; RelayDownstream gates the downstream PUBLISHER on firefly.eda.tracing.brokers.enabled).
 *
 * Not `final`: the deprecated Firefly\Eda\Postgres\Outbox\OutboxRelay extends it.
 */
class OutboxRelay
{
    private readonly EdaTracing $tracing;

    private readonly OutboxClaimStrategy $claim;

    private readonly string $claimant;

    /** @var (Closure(): DateTimeImmutable)|null */
    private readonly ?Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventPublisher $downstream,
        ?OutboxClaimStrategy $claim = null,
        private readonly int $batchSize = 50,
        private readonly int $maxAttempts = 3,
        ?EdaTracing $tracing = null,
        private readonly float $leaseSeconds = 300.0,
        ?string $claimant = null,
        ?Closure $clock = null,
    ) {
        if ($downstream instanceof OutboxWriter) {
            throw new LogicException('OutboxRelay downstream must NOT be the outbox writer (DatabaseEventPublisher / PostgresEventPublisher) — it would re-insert PENDING rows into the same outbox (infinite loop). Configure firefly.eda.database.relay.downstream-provider=rabbitmq|kafka.');
        }

        $this->tracing = $tracing ?? new NoOpEdaTracing;
        $this->claim = $claim ?? OutboxClaimStrategies::configured(OutboxClaimStrategies::AUTO);
        $this->claimant = $claimant ?? LeaseClock::claimant();
        $this->clock = $clock;
    }

    public function claimant(): string
    {
        return $this->claimant;
    }

    public function relayBatch(): int
    {
        $claim = OutboxClaim::at($this->claimant, $this->batchSize, LeaseClock::now($this->clock), $this->leaseSeconds);
        $relayed = 0;

        foreach (ClaimableRows::load($this->connection, $this->claim->claim($this->connection, $claim), $this->claimant) as $row) {
            $id = OutboxRow::asInt($row->id);

            try {
                // The envelope is rebuilt ONLY so the seam has a traceparent to continue and a message id to name the
                // span with — the downstream still receives the row's own four columns, byte for byte.
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

                $relayed += ClaimableRows::owned($this->connection, $id, $this->claimant)->update([
                    'status' => OutboxSchema::STATUS_PUBLISHED,
                    'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
                    'claimed_by' => null,
                    'claimed_until' => null,
                ]);
            } catch (Throwable $e) {
                $attempts = OutboxRow::asInt($row->attempts) + 1;
                $failed = $attempts >= $this->maxAttempts;

                ClaimableRows::owned($this->connection, $id, $this->claimant)->update([
                    'attempts' => $attempts,
                    'status' => $failed ? OutboxSchema::STATUS_FAILED : OutboxSchema::STATUS_PENDING,
                    'error_message' => $e->getMessage(),
                    'failed_at' => $failed ? $this->connection->raw('CURRENT_TIMESTAMP') : null,
                    'claimed_by' => null,
                    'claimed_until' => null,
                ]);
            }
        }

        return $relayed;
    }
}

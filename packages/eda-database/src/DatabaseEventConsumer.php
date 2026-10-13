<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Closure;
use DateTimeImmutable;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\Database\Outbox\Claim\ClaimableRows;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategy;
use Firefly\Eda\Database\Outbox\LeaseClock;
use Firefly\Eda\Database\Outbox\OutboxRow;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\EventEnvelope;
use Illuminate\Database\Connection;
use JsonException;
use Throwable;

/**
 * The TERMINAL in-process delivery path firefly:eda:consume drives: it claims committed PENDING outbox rows and hands
 * them to ConsumerLoop, which delivers them to the #[EventListener] handlers in the SubscriberRegistry and then
 * ack()s or nack()s. There is NO EventPublisher call anywhere in it, so a consumer can never feed its own output back
 * into the table it reads.
 *
 * A CLAIM IS A LEASE. poll() asks the OutboxClaimStrategy for one row; the strategy leases it to this consumer
 * (`claimed_by`, `claimed_until = now + leaseSeconds`) and commits that before the handler runs, so any number of
 * consumers — on any driver — can poll the same outbox and never get the same row while its lease lasts. ack() and
 * nack() settle only a row this consumer still holds (ClaimableRows::owned()): a consumer that ran past its lease, or
 * crashed and came back, finds the row gone to another claimant and settles nothing. A crash leaves the row leased
 * until the lease ends; then the next claimant takes it — exactly-once delivery while consumers live within their
 * lease, at-least-once across a crash. Size `lease-seconds` above your slowest handler.
 *
 * poll() claims FIRST and waits only when nothing was claimable, then claims once more: a row committed while nobody
 * waited is delivered at once. The wait is `awaitWork` — a short sleep here, the NOTIFY wait in PostgresEventConsumer
 * — and any failure inside it is swallowed, because the claim, not the wake-up, is the source of truth.
 *
 * Not `final`: PostgresEventConsumer extends it with LISTEN/NOTIFY.
 */
class DatabaseEventConsumer implements EventConsumer
{
    protected readonly OutboxClaimStrategy $claim;

    protected readonly string $claimant;

    /** @var Closure(int): void */
    private readonly Closure $awaitWork;

    /** @var (Closure(): DateTimeImmutable)|null */
    private readonly ?Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $awaitWork  blocks up to the given milliseconds for new work
     * @param  (Closure(): DateTimeImmutable)|null  $clock  the lease clock (tests pin it; production reads the system clock)
     */
    public function __construct(
        protected readonly Connection $connection,
        ?OutboxClaimStrategy $claim = null,
        protected readonly int $maxAttempts = 3,
        protected readonly float $leaseSeconds = 300.0,
        protected readonly int $pollIntervalMs = 500,
        ?string $claimant = null,
        ?Closure $awaitWork = null,
        ?Closure $clock = null,
    ) {
        $this->claim = $claim ?? OutboxClaimStrategies::configured(OutboxClaimStrategies::AUTO);
        $this->claimant = $claimant ?? LeaseClock::claimant();
        $this->awaitWork = $awaitWork ?? function (int $timeoutMs): void {
            usleep(max(0, min($timeoutMs, $this->pollIntervalMs)) * 1000);
        };
        $this->clock = $clock;
    }

    public function claimant(): string
    {
        return $this->claimant;
    }

    /** Destinations are irrelevant here: the consumer claims every PENDING row of the outbox. */
    public function subscribe(array $destinations): void {}

    public function start(): void {}

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        $received = $this->claimOne();
        if ($received !== null) {
            return $received;
        }

        try {
            ($this->awaitWork)($timeoutMs);
        } catch (Throwable) {
            // The wait is an optimization; the claim below delivers regardless.
        }

        return $this->claimOne();
    }

    public function ack(ReceivedEnvelope $received): void
    {
        ClaimableRows::owned($this->connection, OutboxRow::asInt($received->deliveryTag), $this->claimant)->update([
            'status' => OutboxSchema::STATUS_PUBLISHED,
            'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
            'claimed_by' => null,
            'claimed_until' => null,
        ]);
    }

    /**
     * A handler failure (requeue: true) counts an attempt and frees the row for an immediate retry, or FAILS it past
     * maxAttempts; a poison record (requeue: false) FAILS at once — retrying bytes nobody can decode changes nothing.
     */
    public function nack(ReceivedEnvelope $received, bool $requeue = true): void
    {
        $id = OutboxRow::asInt($received->deliveryTag);
        $row = ClaimableRows::owned($this->connection, $id, $this->claimant)->first();
        if ($row === null) {
            return; // the lease is gone: the row is another claimant's now
        }

        $attempts = OutboxRow::asInt($row->attempts) + 1;
        $failed = ! $requeue || $attempts >= $this->maxAttempts;

        ClaimableRows::owned($this->connection, $id, $this->claimant)->update([
            'attempts' => $attempts,
            'status' => $failed ? OutboxSchema::STATUS_FAILED : OutboxSchema::STATUS_PENDING,
            'error_message' => $received->isPoison()
                ? 'undecodable outbox row: '.($received->failure?->getMessage() ?? 'unknown')
                : 'in-process handler failed',
            'failed_at' => $failed ? $this->connection->raw('CURRENT_TIMESTAMP') : null,
            'claimed_by' => null,
            'claimed_until' => null,
        ]);
    }

    public function stop(): void {}

    private function claimOne(): ?ReceivedEnvelope
    {
        $claim = OutboxClaim::at($this->claimant, 1, LeaseClock::now($this->clock), $this->leaseSeconds);
        $row = ClaimableRows::load($this->connection, $this->claim->claim($this->connection, $claim), $this->claimant)->first();

        if ($row === null) {
            return null;
        }

        $id = OutboxRow::asInt($row->id);

        try {
            return new ReceivedEnvelope(new EventEnvelope(
                OutboxRow::asString($row->event_type),
                OutboxRow::asString($row->destination),
                OutboxRow::asMap($row->payload),
                OutboxRow::asStringMap($row->headers),
            ), $id);
        } catch (JsonException $e) {
            return ReceivedEnvelope::poison(OutboxRow::asString($row->payload), $id, $e, OutboxRow::asString($row->destination));
        }
    }
}

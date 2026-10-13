<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\Database\Outbox\OutboxWriter;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The outbox WRITER: publish() INSERTs ONE firefly_eda_outbox row ON THE CONNECTION IT WAS BUILT WITH. Called inside
 * a transaction — which #[Transactional] and the OutboxPreCommitHook guarantee for domain events — the INSERT enlists
 * in it and commits or rolls back ATOMICALLY with the aggregate: the genuine same-transaction guarantee, on every
 * driver Laravel speaks, because it is nothing more than one more statement in the caller's unit of work. It uses only
 * ConnectionInterface methods (table()/raw()/statement()), so any Laravel connection will do.
 *
 * WHY THE REGISTRY IS A REQUIRED CONSTRUCTOR DEPENDENCY. EventListenerWiringPass subscribes every compiled
 * #[EventListener] by calling subscribe() on the BOUND EventPublisher — under an outbox provider, this class. A writer
 * whose subscribe() dropped the handler would leave the registry the consumer delivers into permanently empty, and
 * the consumer would ack every row it delivered to nobody (the silent-data-loss defect CapstoneOutboxDeliveryTest
 * pins). Requiring the registry makes a writer that cannot deliver its subscriptions unconstructible.
 *
 * THE PRODUCER SPAN WRAPS THE INSERT. The whole body runs inside EdaTracing::tracePublish(), so the `traceparent` the
 * span stamps is on the row that commits with the aggregate — the one adapter family where the producer span and the
 * durable record are genuinely atomic. transaction_id comes from `x-correlation-id`, never from a traceparent, so the
 * relay's idempotency key does not change because a request happened to be traced. With tracing off the bound
 * EdaTracing is the NoOp, which calls the closure with the caller's headers unchanged.
 *
 * That holds for BOTH writers of an outbox row: the bound EventPublisher bean (application code calling publish() by
 * hand) and the OutboxPreCommitHook, which builds its own writer per event and — under an outbox provider, where the
 * after-commit leg is a NoOp — is the ONLY path a DomainEvent takes. Both are handed the same gated EdaTracing.
 * Downstream of the row the trace continues two ways: the terminal in-process consumer hands the claimed row's
 * headers to SubscriberRegistrySink, whose traceConsume() continues the traceparent it finds; the OPTIONAL relay
 * wraps its forward to a downstream broker in traceConsume() itself, so the broker adapter's own producer span does
 * not start a new root. Either way the trace runs unbroken across the commit boundary.
 *
 * Not `final`: PostgresEventPublisher extends it through afterInsert() to add the in-transaction pg_notify.
 */
class DatabaseEventPublisher implements EventPublisher, OutboxWriter
{
    protected readonly EdaTracing $tracing;

    public function __construct(
        protected readonly ConnectionInterface $connection,
        protected readonly SubscriberRegistry $registry,
        protected readonly string $channel = 'firefly_eda_events',
        ?EdaTracing $tracing = null,
    ) {
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

    /**
     * Records the pattern on the SHARED SubscriberRegistry — the same singleton the outbox auto-configuration hands to
     * this writer and that firefly:eda:consume feeds every claimed envelope into. Delivery is the consumer's job.
     */
    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
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

            $this->afterInsert($id);
        });
    }

    /**
     * Runs inside the traced closure, on the same connection — so inside the same transaction — as the INSERT. A no-op
     * here; PostgresEventPublisher overrides it with the in-transaction pg_notify.
     */
    protected function afterInsert(int $id): void {}

    public function start(): void {}

    public function stop(): void {}
}

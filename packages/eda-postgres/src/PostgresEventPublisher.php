<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The Postgres EventPublisher: publish() INSERTs ONE firefly_eda_outbox row ON THE CURRENT CONNECTION. When called
 * inside a DB transaction (which M8's #[Transactional] and the OutboxPreCommitHook guarantee for domain events), the
 * INSERT enlists in that transaction and commits ATOMICALLY with the aggregate — the genuine same-tx guarantee. When
 * emitNotify is true (pgsql), it also fires `SELECT pg_notify('<channel>', id)` — inside the SAME tx, so Postgres
 * queues the NOTIFY and delivers it exactly when the aggregate COMMITS, waking the in-process consumer's LISTEN with
 * low latency (M2). The in-process consumer (PostgresEventConsumer) claims PENDING rows and drives #[EventListener]
 * handlers; the OPTIONAL firefly:outbox:relay only fronts a distinct downstream broker. start()/stop() are no-ops
 * (the relay/consumer own their own connections). It uses only ConnectionInterface methods (table()/statement()/raw())
 * — no getPdo()/getDriverName() — so ConnectionInterface is the correct ctor type; the driver-gated emitNotify flag is
 * computed by the caller from the concrete Connection. Reflection-free.
 *
 * WHY THE REGISTRY IS A REQUIRED CONSTRUCTOR DEPENDENCY (silent-data-loss regression, CapstoneOutboxDeliveryTest):
 * subscribe() USED TO BE AN EMPTY NO-OP while this very docblock claimed it "feeds the shared SubscriberRegistry".
 * That mattered because EventListenerWiringPass — the boot pass that turns the app's compiled #[EventListener]
 * manifest into live subscriptions — resolves the bound EventPublisher and calls subscribe() ON IT. Under
 * firefly.eda.provider=postgres the bound publisher is THIS class, so every listener the app compiled was handed to
 * a method that discarded it, and the SubscriberRegistry the terminal consumer delivers into stayed permanently
 * empty. The failure was silent rather than loud because SubscriberRegistry::deliver() on an empty registry returns
 * normally: ConsumerLoop read that as a successful delivery and called ack(), flipping the outbox row
 * PENDING -> PUBLISHED, never to be claimed again. Domain events were written same-transaction exactly as promised,
 * drained exactly as promised, and thrown away — no exception, no log line, nothing. Making the registry a REQUIRED
 * parameter (mirroring RabbitMqEventPublisher and KafkaEventPublisher, which have always taken one) means an
 * instance of this class that cannot deliver its subscriptions is no longer constructible at all.
 *
 * THE PRODUCER SPAN WRAPS THE INSERT. publish() runs its whole body — the row and the pg_notify — inside
 * EdaTracing::tracePublish(), so the `traceparent` that span produced is written onto the row itself, and the row
 * carrying it is the one that COMMITS WITH THE AGGREGATE. This is the one adapter where the producer span and the
 * durable record are genuinely atomic: a broker adapter's span ends when the socket accepts the message and the
 * two can still disagree, while here either both the aggregate and its traced outbox row exist or neither does.
 *
 * That holds for BOTH writers of an outbox row, which is the only way the sentence above is worth anything: the
 * bound EventPublisher bean (application code calling publish() by hand) and OutboxPreCommitHook, which builds its
 * own instance per event and is — under firefly.eda.provider=postgres, where commandEventPublisher() NoOps the
 * after-commit leg — the ONLY path a DomainEvent takes. Both are handed the same gated EdaTracing by
 * PostgresOutboxAutoConfiguration.
 *
 * Downstream of the row the trace continues two different ways. PostgresEventConsumer, the terminal in-process
 * path, hands the claimed row's headers to SubscriberRegistrySink, whose traceConsume() continues whatever
 * traceparent it finds. The OPTIONAL relay hands them to a downstream EventPublisher instead, so OutboxRelay
 * wraps that forward in traceConsume() itself — otherwise the downstream adapter's own producer span would start
 * a new root and overwrite the row's traceparent. Either way the trace runs unbroken from the request that
 * changed the aggregate to the listener (or broker) that reacted to it, across a commit boundary.
 */
final class PostgresEventPublisher implements EventPublisher
{
    private readonly EdaTracing $tracing;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SubscriberRegistry $registry,
        private readonly string $channel = 'firefly_eda_events',
        private readonly bool $emitNotify = false,
        ?EdaTracing $tracing = null,
    ) {
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

    /**
     * Records the pattern on the SHARED SubscriberRegistry — the same singleton PostgresOutboxAutoConfiguration
     * hands to this publisher and that firefly:eda:consume resolves as the ConsumerLoop's sink. The publisher itself
     * never invokes handlers: delivery is the terminal consumer's job, driven off committed rows.
     */
    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    /**
     * Routed through EdaTracing::tracePublish() — the seam InMemoryEventBus and QueueEventBus have always
     * used, and the reason it takes the headers rather than returning them: propagation means WRITING
     * something on the envelope, so the implementation hands the send closure the headers to carry and the
     * adapter builds the row from those. Before this, the three broker adapters built their envelopes
     * themselves and no producer span existed on the wire at all; their CONSUME side was already traced
     * through SubscriberRegistrySink, so a `traceparent` a foreign producer put in the headers was continued
     * while one of our own was never written. A trace that stops at the broker is a trace of half a system.
     *
     * The ENTIRE body is inside the closure, INSERT and pg_notify together, so the span covers the durable
     * write rather than sitting beside it. transaction_id is still derived from the closure's
     * `x-correlation-id` and never from a traceparent: the relay's idempotency key must not change just
     * because a request happened to be traced.
     *
     * With tracing off the bound EdaTracing is the NoOp, which calls $send with the caller's headers
     * unchanged, so this path is byte-for-byte what it was.
     *
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

            if ($this->emitNotify) {
                // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly
                // when the PENDING row becomes visible. statement() is on ConnectionInterface. (sqlite: emitNotify=false.)
                $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
            }
        });
    }

    public function start(): void {}

    public function stop(): void {}
}

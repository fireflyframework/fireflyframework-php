<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

/**
 * The run-until-stopped consume PORT M9's EventPublisher never had (start()/stop() existed; a poll loop did not).
 * A broker adapter (SP-4) implements this; ConsumerLoop drives it and firefly:eda:consume hosts it. PHP-FPM has no
 * in-process event loop, so consumption is a separate long-running process — this is the honest map of the
 * references' in-process asyncio/Spring listener containers onto Laravel.
 */
interface EventConsumer
{
    /**
     * Bind this consumer to the broker routes it should receive from, before the first poll().
     *
     * THE ROUTING CONTRACT — the one thing an adapter must get right, and the one thing that was wrong.
     *
     * Every element of $destinations is a DESTINATION: the value a publisher passed as the FIRST argument of
     * EventPublisher::publish(string $destination, string $eventType, ...), or an fnmatch glob over such values.
     * It is emphatically NOT an event type. `$eventType` — publish()'s SECOND argument, the thing
     * #[EventListener] patterns are written against — never appears here and must never be matched against a
     * destination. The two are independent namespaces: an app may publish `order.created`, `order.shipped` and
     * `order.cancelled` all to one destination `orders`, or the same event type to several destinations.
     * ConsumeEventsCommand used to pass the manifest's event-type patterns into this method, which bound
     * consumers to routes no publisher ever wrote to; they polled forever and received nothing, silently. See
     * TopicSubscriptionResolver for the full account and for where destinations legitimately come from.
     *
     * An adapter translates each destination into its own subscription model and MUST support the fnmatch
     * wildcard `*`, because the resolver's safe default is the catch-all `['*']` — "every destination this
     * application publishes to":
     *
     *   - RabbitMQ: an AMQP routing key bound to the work queue (`*`/`**` widen to the AMQP catch-all `#`).
     *   - Kafka: a topic name, or a `^`-prefixed librdkafka regex when the destination contains `*`.
     *   - Postgres: ignored — that adapter is bound to a LISTEN channel and claims every PENDING outbox row.
     *
     * Over-subscribing at the broker is SAFE and expected: SubscriberRegistry applies the fine-grained fnmatch on
     * EventEnvelope::$eventType after receipt, so an envelope no #[EventListener] wants is simply dropped.
     * Under-subscribing is not recoverable — the message never reaches the process. When in doubt, bind wider.
     *
     * @param  list<string>  $destinations  publisher destinations (or fnmatch globs over them); never empty
     */
    public function subscribe(array $destinations): void;

    public function start(): void;

    /** Block up to $timeoutMs for one message; null on timeout (no message). */
    public function poll(int $timeoutMs): ?ReceivedEnvelope;

    public function ack(ReceivedEnvelope $received): void;

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void;

    public function stop(): void;
}

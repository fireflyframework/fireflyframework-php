<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\Listener\EventListenerManifest;

/**
 * Resolves the BROKER DESTINATIONS firefly:eda:consume binds through EventConsumer::subscribe().
 *
 * THE DEFECT THIS CLASS USED TO BE. It returned the compiled #[EventListener] patterns — `user.*`, `order.created`
 * — and ConsumeEventsCommand handed them straight to EventConsumer::subscribe(). But an #[EventListener] pattern
 * is an EVENT TYPE, and every publisher in the framework routes by DESTINATION, the other argument entirely:
 *
 *     EventPublisher::publish(string $destination, string $eventType, array $payload, array $headers = [])
 *
 *     KafkaEventPublisher    -> $producer->newTopic($destination)      (topic == destination, verbatim)
 *     RabbitMqEventPublisher -> parseDestination($destination)         (exchange + routing key, from destination)
 *     PostgresEventPublisher -> INSERT ... destination = $destination  (outbox column)
 *
 * Nothing anywhere derives a destination from an event type. So an ordinary app publishing `order.created` to a
 * topic called `orders` got a Kafka consumer subscribed to the regex `^order\..*` and a RabbitMQ queue bound to
 * the routing key `order.*` — neither of which can ever match `orders`. There was no exception, no warning and no
 * failed health check: the worker started, polled forever, and consumed nothing. The two adapter round-trip tests
 * did not catch it because they publish to `<exchange>/order.created`, i.e. they happen to choose a routing key
 * equal to the event type, which is a convention and not a contract.
 *
 * THE CONTRACT NOW. Destinations come from the two places that actually know them, in precedence order:
 *
 *   1. What the operator asked for — `--destination` on the command, else the `firefly.eda.destinations` config
 *      list. This is authoritative and is returned verbatim (deduplicated): it is also how one manifest gets
 *      sharded across several workers, one destination each.
 *   2. The union of the `destinations` every compiled listener declared — but ONLY when EVERY listener declared
 *      at least one. A listener that declared none is a listener whose destination is unknowable from the
 *      manifest, and binding just the destinations its siblings named would drop its events silently: precisely
 *      the failure this class exists to prevent. One undeclared listener therefore re-widens the whole worker.
 *   3. Otherwise the catch-all CATCH_ALL (`*`).
 *
 * WHY A CATCH-ALL DEFAULT IS THE CORRECT ONE. Broker subscription is a COARSE filter here; the fine filter is
 * SubscriberRegistry's fnmatch on EventEnvelope::$eventType, applied after receipt. Over-subscribing therefore
 * costs bandwidth and nothing else, while under-subscribing loses events invisibly — so when the destinations
 * are not known, the safe answer is "everything, then filter". Both wildcard-capable adapters already implement
 * exactly this translation for `*`: RabbitMqEventConsumer::toRoutingKey() widens `*` to the AMQP catch-all `#`,
 * and KafkaEventConsumer::toTopic() rewrites it to the librdkafka regex `^.*`. PostgresEventConsumer ignores the
 * argument outright (it is bound to a LISTEN channel, not to per-destination routes), so the default is inert
 * there. An operator who wants the narrow subscription back sets `firefly.eda.destinations`.
 */
final class TopicSubscriptionResolver
{
    /**
     * The "every destination this application publishes to" wildcard. It is an fnmatch glob, the same dialect
     * #[EventListener] patterns and adapter destinations are written in, so an adapter that already translates
     * wildcards needs no special case for it.
     */
    public const string CATCH_ALL = '*';

    /**
     * @param  list<string>  $configured  operator-supplied destinations (--destination, else firefly.eda.destinations)
     * @return list<string> broker destinations, never event-type patterns; never empty
     */
    public function resolve(EventListenerManifest $manifest, array $configured = []): array
    {
        if ($configured !== []) {
            return $this->distinct($configured);
        }

        $listeners = $manifest->all();
        $declared = [];

        foreach ($listeners as $descriptor) {
            if ($descriptor->destinations === []) {
                // Unknowable destination -> narrowing is no longer safe for ANY listener. Bail to the catch-all
                // rather than bind a subset that would silently starve this one.
                return [self::CATCH_ALL];
            }

            foreach ($descriptor->destinations as $destination) {
                $declared[] = $destination;
            }
        }

        // $listeners === [] falls through here too: a worker with no compiled listeners has nothing to narrow
        // toward, and an empty subscription list is a shape several adapters would reject outright.
        return $declared === [] ? [self::CATCH_ALL] : $this->distinct($declared);
    }

    /**
     * @param  list<string>  $destinations
     * @return list<string>
     */
    private function distinct(array $destinations): array
    {
        return array_values(array_unique($destinations));
    }
}

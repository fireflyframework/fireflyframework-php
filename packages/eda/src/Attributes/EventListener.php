<?php

declare(strict_types=1);

namespace Firefly\Eda\Attributes;

use Attribute;

/**
 * Marks a public bean method as an eda broker-bus listener for one or more event-type PATTERNS (fnmatch globs like
 * "user.*"). This is a DIFFERENT surface from the shipped in-process #[AsEventListener] (which listens for PHP
 * event CLASSES dispatched synchronously): #[EventListener] subscribes to broker event-type strings and is
 * delivered by the eda adapters. INERT METADATA ONLY — discovery + subscription live in EventListenerScanner
 * (the sole reflection site) → EventListenerManifest → EventListenerWiringPass. IS_REPEATABLE: a method may carry
 * several. A single string is normalised to a one-element pattern list; order follows the #[Order] convention
 * (lower first), default 0, and IS honoured at dispatch — see EventListenerManifest::ordered().
 *
 * PATTERNS ARE EVENT TYPES, `destinations` ARE BROKER ROUTES. The two are separate namespaces and this attribute
 * is the only place an application can relate them:
 *
 *   - `$patterns` are fnmatch globs matched IN-PROCESS by SubscriberRegistry against EventEnvelope::$eventType,
 *     i.e. the SECOND argument of EventPublisher::publish($destination, $eventType, ...).
 *   - `$destinations` are broker routes — the FIRST argument of publish() — that a long-running consumer must
 *     bind before anything can arrive at all: a Kafka topic, an AMQP `exchange/routingKey` (or just the routing
 *     key), a logical outbox destination. They may themselves be fnmatch globs over destination names.
 *
 * Declaring `destinations` is OPTIONAL and purely an optimisation: firefly:eda:consume subscribes to the union of
 * the declared destinations only when EVERY compiled listener declares at least one, and otherwise falls back to
 * the catch-all `*` so that no event can be missed. See TopicSubscriptionResolver, which owns that rule and
 * explains why a partially-declared manifest MUST NOT be narrowed.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @var list<string> */
    public readonly array $patterns;

    /** @var list<string> */
    public readonly array $destinations;

    /**
     * @param  string|array<int, string>  $patterns  event-type fnmatch glob(s)
     * @param  string|array<int, string>  $destinations  broker route(s) a consumer must bind for these events
     */
    public function __construct(
        string|array $patterns = [],
        public readonly int $order = 0,
        string|array $destinations = [],
    ) {
        $this->patterns = is_string($patterns) ? [$patterns] : array_values($patterns);
        $this->destinations = is_string($destinations) ? [$destinations] : array_values($destinations);
    }
}

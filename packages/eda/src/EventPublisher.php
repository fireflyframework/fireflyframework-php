<?php

declare(strict_types=1);

namespace Firefly\Eda;

/**
 * The eda broker-bus PORT (Spring's/pyfly's EventPublisher, ported): a publisher + pattern-subscriber + lifecycle,
 * ONE seam. Application code and the wiring pass program to this interface; the in-memory and queue adapters are
 * the only implementations shipped in M9. A handler is a `callable(EventEnvelope): void`; a subscription pattern
 * is an fnmatch-style event-type glob (e.g. "user.*"). start()/stop() are no-ops for in-memory; real broker
 * adapters (SP-4) open/close connections there.
 */
interface EventPublisher
{
    public function subscribe(string $eventTypePattern, callable $handler): void;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void;

    public function start(): void;

    public function stop(): void;
}

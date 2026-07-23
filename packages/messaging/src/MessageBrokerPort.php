<?php

declare(strict_types=1);

namespace Firefly\Messaging;

/**
 * The raw-bytes broker PORT (pyfly messaging/ports/outbound.py parity): publish bytes to a topic, subscribe a
 * handler to a topic within an optional consumer group, and start/stop the transport. A handler is a
 * `callable(Message): void`. The in-memory and queue adapters are the only implementations shipped in M9; real
 * brokers (Kafka/RabbitMQ) implement this in their own SP-4 packages.
 */
interface MessageBrokerPort
{
    /**
     * @param  array<string, string>  $headers
     */
    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void;

    public function subscribe(string $topic, callable $handler, ?string $group = null): void;

    public function start(): void;

    public function stop(): void;
}

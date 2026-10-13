<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Firefly\Messaging\MessageBrokerPort;

/**
 * A MessageBrokerPort that consumes through a LISTENER CONTAINER (the Kafka and RabbitMQ adapters): it owns the
 * retries and the dead letters of each delivery, and acknowledges a delivery only after its listeners have handled
 * it or it has been dead-lettered. MessageListenerWiringPass therefore hands it the bare listener plus the
 * listener's ListenerOptions instead of wrapping the listener in RetryingMessageHandler (PyFly's
 * `manages_listener_errors`).
 *
 * poll() consumes at most one delivery, waiting up to $timeoutMs, and answers whether one was processed; it is what
 * `php artisan firefly:messaging:consume` drives. Subscriptions are made at boot, before the first poll().
 */
interface ListenerContainerBroker extends MessageBrokerPort
{
    public function subscribeListener(string $topic, callable $handler, ?string $group, ListenerOptions $options): void;

    public function poll(int $timeoutMs): bool;
}

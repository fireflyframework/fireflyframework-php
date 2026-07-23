<?php

declare(strict_types=1);

use Firefly\Messaging\Broker\DispatchMessageJob;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Container\Container;

/**
 * Pins the fail-loud guard in DispatchMessageJob::handle(): when the container's bound MessageBrokerPort singleton
 * is NOT a QueueMessageBroker (misconfiguration — e.g. firefly.messaging.provider left at the in-memory default
 * while a worker still picks up a queued DispatchMessageJob), handle() must throw loudly rather than silently drop
 * the message. A silent-return variant would pass every other test in this suite while quietly losing messages on
 * the worker. Mirrors packages/eda/tests/Bus/DispatchEventJobTest.php.
 */
it('throws MessagingException when the resolved MessageBrokerPort is not the QueueMessageBroker adapter', function () {
    $container = new Container;
    $container->instance(MessageBrokerPort::class, new InMemoryMessageBroker);

    $job = new DispatchMessageJob('orders', 'bytes', null, []);

    expect(fn () => $job->handle($container))->toThrow(MessagingException::class);
});

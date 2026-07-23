<?php

declare(strict_types=1);

use Firefly\Eda\Bus\DispatchEventJob;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Illuminate\Container\Container;

/**
 * Pins the fail-loud guard in DispatchEventJob::handle(): when the container's bound EventPublisher singleton is
 * NOT a QueueEventBus (misconfiguration — e.g. firefly.eda.provider left at the in-memory default while a worker
 * still picks up a queued DispatchEventJob), handle() must throw loudly rather than silently drop the event. A
 * silent-return variant would pass every other test in this suite while quietly losing events on the worker.
 */
it('throws InfrastructureException when the resolved EventPublisher is not the QueueEventBus adapter', function () {
    $container = new Container;
    $container->instance(EventPublisher::class, new InMemoryEventBus(new SubscriberRegistry));

    $job = new DispatchEventJob(new EventEnvelope('order.placed', 'firefly.events', ['id' => 3]));

    expect(fn () => $job->handle($container))->toThrow(InfrastructureException::class);
});

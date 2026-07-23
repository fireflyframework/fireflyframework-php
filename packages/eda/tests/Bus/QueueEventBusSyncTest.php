<?php

declare(strict_types=1);

use Firefly\Eda\Bus\QueueEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tests\Support\QueueEventBusTestCase;
use Illuminate\Contracts\Config\Repository;

uses(QueueEventBusTestCase::class);

/**
 * On the `sync` queue driver, dispatch runs the job inline on the SAME process. The job resolves EventPublisher
 * from the container — so we bind the very QueueEventBus we publish through as that singleton, exactly as the
 * auto-config does at boot — and the worker-side deliver() then match+invokes the registry the wiring pass would
 * have populated. This proves end-to-end delivery without a running worker.
 */
it('delivers through the worker path under the sync driver', function () {
    /** @var QueueEventBusTestCase $this */
    $app = $this->busApp();

    /** @var Repository $config */
    $config = $app->make('config');
    $config->set('queue.default', 'sync');

    $bus = new QueueEventBus(new SubscriberRegistry, $app, 'sync', null);
    $app->instance(EventPublisher::class, $bus); // the job resolves this singleton on the (sync) worker

    $received = [];
    $bus->subscribe('order.*', function (EventEnvelope $e) use (&$received): void {
        $received[] = $e;
    });

    $bus->publish('firefly.events', 'order.placed', ['id' => 2], ['trace' => 'abc']);

    // Assert the WHOLE envelope survives the publish -> serialize -> job -> deliver round-trip, not just the
    // event type: a dropped/garbled headers, payload, destination or timestamp field must break this.
    expect($received)->toHaveCount(1);
    $envelope = $received[0];
    expect($envelope->eventType)->toBe('order.placed')
        ->and($envelope->destination)->toBe('firefly.events')
        ->and($envelope->payload)->toBe(['id' => 2])
        ->and($envelope->headers)->toBe(['trace' => 'abc'])
        ->and($envelope->timestamp)->toBeInstanceOf(DateTimeImmutable::class);
});

<?php

declare(strict_types=1);

use Firefly\Messaging\Broker\QueueMessageBroker;
use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\Tests\Support\QueueMessageBrokerTestCase;
use Illuminate\Contracts\Config\Repository;

uses(QueueMessageBrokerTestCase::class);

/**
 * On the `sync` queue driver, dispatch runs the job inline on the SAME process. The job resolves MessageBrokerPort
 * from the container — so we bind the very QueueMessageBroker we publish through as that singleton, exactly as the
 * auto-config does at boot — and the worker-side deliver() then invokes the subscribers the wiring pass would have
 * populated. This proves end-to-end delivery without a running worker, and would FAIL if the job instead resolved a
 * fresh, empty-subscriptions broker (e.g. by binding QueueMessageBroker::class directly instead of the port).
 */
it('delivers through the worker path under the sync driver', function () {
    /** @var QueueMessageBrokerTestCase $this */
    $app = $this->app();

    /** @var Repository $config */
    $config = $app->make('config');
    $config->set('queue.default', 'sync');

    $broker = new QueueMessageBroker($app, 'sync', null);
    $app->instance(MessageBrokerPort::class, $broker); // the job resolves this singleton on the (sync) worker

    $received = [];
    $broker->subscribe('orders', function (Message $m) use (&$received): void {
        $received[] = $m->value;
    });

    $broker->publish('orders', 'bytes-1', 'k1');

    expect($received)->toBe(['bytes-1']);
});

/**
 * Asserts the WHOLE Message survives the publish -> serialize -> job -> deliver round-trip (bytes value, key, AND
 * headers) — not just the topic. A dropped/garbled key or headers field must break this, exactly as eda's analogous
 * envelope round-trip test pins every EventEnvelope field.
 */
it('round-trips the full Message (value, key, headers) through job serialization', function () {
    /** @var QueueMessageBrokerTestCase $this */
    $app = $this->app();

    /** @var Repository $config */
    $config = $app->make('config');
    $config->set('queue.default', 'sync');

    $broker = new QueueMessageBroker($app, 'sync', null);
    $app->instance(MessageBrokerPort::class, $broker);

    $received = [];
    $broker->subscribe('payments', function (Message $m) use (&$received): void {
        $received[] = $m;
    });

    $broker->publish('payments', 'bytes-2', 'k2', ['trace' => 'abc']);

    expect($received)->toHaveCount(1);
    $message = $received[0];
    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->topic)->toBe('payments')
        ->and($message->value)->toBe('bytes-2')
        ->and($message->key)->toBe('k2')
        ->and($message->headers)->toBe(['trace' => 'abc']);
});

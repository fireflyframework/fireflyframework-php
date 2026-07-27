<?php

declare(strict_types=1);

use Firefly\Eda\Bus\QueueEventBus;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tests\Support\EdaQueueCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;

uses(EdaQueueCapstoneTestCase::class);

it('delivers a published event through the queue(sync) worker path to the #[EventListener]', function () {
    /** @var EdaQueueCapstoneTestCase $this */
    $app = $this->app();

    // Pin that this capstone genuinely exercises the QUEUE provider (not a silent in-memory fallback): the
    // resolved bus IS a QueueEventBus, so a broken provider-selection would fail here, self-contained.
    expect($app->make(EventPublisher::class))->toBeInstanceOf(QueueEventBus::class);

    $app->make(EventPublisher::class)->publish('firefly.events', 'order.placed', ['id' => 8]);

    expect($app->make(ListenerSpy::class)->seen)->toBe(['order.placed']);
});

it('dead-letters a throwing listener on the queue(sync) worker path, without failing the job', function () {
    /** @var EdaQueueCapstoneTestCase $this */
    $app = $this->app();

    $app->make(EventPublisher::class)->publish('firefly.events', 'fail.now', ['x' => 1]);

    /** @var DeadLetterStore $dlq */
    $dlq = $app->make(DeadLetterStore::class);
    expect($dlq->all())->toHaveCount(1)
        ->and($dlq->all()[0]->envelope->headers['x-exception'])->toBe('listener boom');
});

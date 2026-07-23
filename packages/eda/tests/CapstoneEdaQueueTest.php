<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tests\Fixtures\Spy;
use Firefly\Eda\Tests\Support\EdaQueueCapstoneTestCase;

uses(EdaQueueCapstoneTestCase::class);

it('delivers a published event through the queue(sync) worker path to the #[EventListener]', function () {
    /** @var EdaQueueCapstoneTestCase $this */
    $app = $this->capstoneApp();

    $app->make(EventPublisher::class)->publish('firefly.events', 'order.placed', ['id' => 8]);

    expect($app->make(Spy::class)->seen)->toBe(['order.placed']);
});

it('dead-letters a throwing listener on the queue(sync) worker path, without failing the job', function () {
    /** @var EdaQueueCapstoneTestCase $this */
    $app = $this->capstoneApp();

    $app->make(EventPublisher::class)->publish('firefly.events', 'fail.now', ['x' => 1]);

    /** @var DeadLetterStore $dlq */
    $dlq = $app->make(DeadLetterStore::class);
    expect($dlq->all())->toHaveCount(1)
        ->and($dlq->all()[0]->envelope->headers['x-exception'])->toBe('listener boom');
});

<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tests\Support\EdaCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;

uses(EdaCapstoneTestCase::class);

it('delivers a published event to the matching #[EventListener] (in-memory)', function () {
    /** @var EdaCapstoneTestCase $this */
    $app = $this->app();

    $app->make(EventPublisher::class)->publish('firefly.events', 'order.placed', ['id' => 7]);

    expect($app->make(ListenerSpy::class)->seen)->toBe(['order.placed']);
});

it('dead-letters a throwing listener after exhausting retries, without escaping (in-memory)', function () {
    /** @var EdaCapstoneTestCase $this */
    $app = $this->app();

    // FailingListener throws on every attempt; retries=2 → 3 attempts → DLQ, no exception escapes publish().
    $app->make(EventPublisher::class)->publish('firefly.events', 'fail.now', ['x' => 1]);

    /** @var DeadLetterStore $dlq */
    $dlq = $app->make(DeadLetterStore::class);
    expect($dlq->all())->toHaveCount(1)
        ->and($dlq->all()[0]->envelope->headers['x-original-topic'])->toBe('firefly.events')
        ->and($dlq->all()[0]->envelope->headers['x-exception'])->toBe('listener boom')
        ->and($dlq->all()[0]->exceptionMessage)->toBe('listener boom')
        ->and($app->make(ListenerSpy::class)->seen)->toBe([]); // order listener untouched
});

<?php

declare(strict_types=1);

use Firefly\Messaging\Broker\QueueMessageBroker;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\Tests\Support\MessagingQueueCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;

uses(MessagingQueueCapstoneTestCase::class);

it('delivers a published message through the queue(sync) worker path to the #[MessageListener]', function () {
    /** @var MessagingQueueCapstoneTestCase $this */
    $app = $this->app();

    // Pin that this capstone genuinely exercises the QUEUE provider (not a silent in-memory fallback): the
    // resolved broker IS a QueueMessageBroker, so a broken provider-selection would fail here, self-contained.
    expect($app->make(MessageBrokerPort::class))->toBeInstanceOf(QueueMessageBroker::class);

    $app->make(MessageBrokerPort::class)->publish('orders', 'bytes-2', 'k2');

    expect($app->make(ListenerSpy::class)->seen)->toBe(['orders']);
});

it('dead-letters a throwing consumer on the queue(sync) worker path, without failing the job', function () {
    /** @var MessagingQueueCapstoneTestCase $this */
    $app = $this->app();

    $app->make(MessageBrokerPort::class)->publish('failing', 'bytes-x');

    /** @var DeadLetterStore $dlq */
    $dlq = $app->make(DeadLetterStore::class);
    expect($dlq->all())->toHaveCount(1)
        ->and($dlq->all()[0]->message->topic)->toBe('failing.DLT');
});

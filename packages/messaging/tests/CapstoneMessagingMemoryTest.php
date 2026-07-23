<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\Tests\Fixtures\Spy;
use Firefly\Messaging\Tests\Support\MessagingCapstoneTestCase;

uses(MessagingCapstoneTestCase::class);

it('delivers a published message to the matching #[MessageListener] (in-memory)', function () {
    /** @var MessagingCapstoneTestCase $this */
    $app = $this->capstoneApp();

    $app->make(MessageBrokerPort::class)->publish('orders', 'bytes-1', 'k1');

    expect($app->make(Spy::class)->seen)->toBe(['orders']);
});

it('dead-letters a throwing consumer after exhausting its per-listener retries, re-keyed to the DLT (in-memory)', function () {
    /** @var MessagingCapstoneTestCase $this */
    $app = $this->capstoneApp();

    // FailingConsumer: retries=2 → 3 attempts → re-keyed to 'failing.DLT', no exception escapes publish().
    $app->make(MessageBrokerPort::class)->publish('failing', 'bytes-x');

    /** @var DeadLetterStore $dlq */
    $dlq = $app->make(DeadLetterStore::class);
    expect($dlq->all())->toHaveCount(1)
        ->and($dlq->all()[0]->message->topic)->toBe('failing.DLT')
        ->and($dlq->all()[0]->message->headers['x-original-topic'])->toBe('failing')
        ->and($dlq->all()[0]->message->headers['x-exception'])->toBe('consumer boom')
        ->and($app->make(Spy::class)->seen)->toBe([]); // orders consumer untouched
});

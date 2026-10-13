<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DeadLetterEntry;
use Firefly\Eda\DeadLetter\DeadLetterQuery;
use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\EventEnvelope;

it('gives every in-memory entry an id and an instant, and finds and removes by selector', function () {
    $store = new InMemoryDeadLetterStore;
    $store->store(new EventEnvelope('order.placed', 'orders'), new RuntimeException('a'));
    $store->store(new EventEnvelope('order.paid', 'orders'), new RuntimeException('b'));
    $store->store(new EventEnvelope('user.created', 'users'), new RuntimeException('c'));

    [$first] = $store->all();

    expect($first->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($first->deadLetteredAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and(array_map(static fn (DeadLetterEntry $e): string => $e->envelope->eventType, $store->find(new DeadLetterQuery(destination: 'orders'))))->toBe(['order.placed', 'order.paid'])
        ->and($store->find(new DeadLetterQuery(eventType: 'user.created')))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(limit: 1)))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(ids: [(string) $first->id])))->toHaveCount(1)
        ->and($store->remove((string) $first->id))->toBeTrue()
        ->and($store->remove((string) $first->id))->toBeFalse()
        ->and($store->all())->toHaveCount(2);
});

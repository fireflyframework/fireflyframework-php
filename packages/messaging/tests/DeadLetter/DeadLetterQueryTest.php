<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\DeadLetterQuery;
use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Messaging\Message;

it('gives every in-memory entry an id and an instant, selects by either topic, and removes by id', function () {
    $store = new InMemoryDeadLetterStore;
    $store->store(new Message('ledger.DLT', 'a', null, ['x-original-topic' => 'ledger']), new RuntimeException('a'));
    $store->store(new Message('audit.DLT', 'b', null, ['x-original-topic' => 'audit']), new RuntimeException('b'));

    [$first] = $store->all();

    expect($first->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($first->deadLetteredAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($store->find(new DeadLetterQuery(topic: 'ledger')))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(topic: 'audit.DLT')))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(topic: 'nowhere')))->toBe([])
        ->and($store->find(new DeadLetterQuery(limit: 1)))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(ids: [(string) $first->id])))->toHaveCount(1)
        ->and($store->remove((string) $first->id))->toBeTrue()
        ->and($store->remove((string) $first->id))->toBeFalse()
        ->and($store->all())->toHaveCount(1);
});

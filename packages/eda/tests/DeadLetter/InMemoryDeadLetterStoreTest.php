<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\EventEnvelope;

it('appends an entry capturing the envelope and the cause', function () {
    $store = new InMemoryDeadLetterStore;
    $envelope = new EventEnvelope('x.y', 'firefly.events');

    $store->store($envelope, new RuntimeException('why'));

    expect($store->all())->toHaveCount(1)
        ->and($store->all()[0]->envelope)->toBe($envelope)
        ->and($store->all()[0]->exceptionClass)->toBe(RuntimeException::class)
        ->and($store->all()[0]->exceptionMessage)->toBe('why');
});

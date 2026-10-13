<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\StoredEvent;

it('round-trips through the eda header form, extras included', function (): void {
    $metadata = new EventMetadata('corr-1', 'cause-1', 'acme', 'user-7', ['x-source' => 'import']);

    expect($metadata->toHeaders())->toBe([
        'x-source' => 'import',
        'x-correlation-id' => 'corr-1',
        'x-causation-id' => 'cause-1',
        'x-tenant-id' => 'acme',
        'x-user-id' => 'user-7',
    ])->and(EventMetadata::fromHeaders($metadata->toHeaders()))->toEqual($metadata)
        ->and((new EventMetadata)->toHeaders())->toBe([]);
});

it('lets the more specific metadata win a merge, and chains causation from the event that caused it', function (): void {
    $merged = (new EventMetadata('corr-1', null, 'acme', null, ['a' => '1']))->merge(new EventMetadata(null, 'cause-2', null, 'u', ['b' => '2']));
    $cause = new StoredEvent('evt-9', 'order-1', 'order', 3, 12, 'order.placed', 1, [], new EventMetadata(null, null, 'acme'), new DateTimeImmutable, new DateTimeImmutable);

    expect($merged)->toEqual(new EventMetadata('corr-1', 'cause-2', 'acme', 'u', ['a' => '1', 'b' => '2']))
        ->and(EventMetadata::causedBy($cause))->toEqual(new EventMetadata('evt-9', 'evt-9', 'acme'));
});

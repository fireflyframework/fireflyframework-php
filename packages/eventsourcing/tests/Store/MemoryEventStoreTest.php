<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\EventSerializationException;
use Firefly\EventSourcing\Publishing\EventStorePublisher;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;
use Firefly\EventSourcing\Upcasting\UpcasterChain;
use Illuminate\Database\ConnectionInterface;

foreach (EventStoreContract::cases() as $case => $assert) {
    it("memory event store {$case}", function () use ($assert): void {
        $assert(static fn (?UpcasterChain $upcasters): EventStore => new MemoryEventStore(upcasters: $upcasters ?? new UpcasterChain));
    });
}

it('appends nothing when the payload cannot be stored or the in-transaction publisher refuses', function (): void {
    $refusing = new class implements EventStorePublisher
    {
        public function inTransaction(?ConnectionInterface $connection, array $events): void
        {
            throw new RuntimeException('outbox down');
        }

        public function afterTransaction(?ConnectionInterface $connection, array $events): void {}
    };
    $store = new MemoryEventStore;
    $refused = new MemoryEventStore(publisher: $refusing);

    expect(fn () => $store->append('o-1', 'order', [EventStoreContract::event(), EventStoreContract::event(payload: ['bad' => INF])], 0))->toThrow(EventSerializationException::class)
        ->and(fn () => $refused->append('o-1', 'order', [EventStoreContract::event()], 0))->toThrow(RuntimeException::class, 'outbox down')
        ->and($store->exists('o-1'))->toBeFalse()
        ->and($store->currentPosition())->toBe(0)
        ->and($refused->exists('o-1'))->toBeFalse()
        ->and($refused->currentPosition())->toBe(0);
});

it('numbers the global stream as it appends, one process being its own commit order', function (): void {
    $store = new MemoryEventStore;
    $stored = $store->append('o-1', 'order', [EventStoreContract::event(), EventStoreContract::event('order.paid')], 0);

    expect(array_map(static fn (StoredEvent $e): ?int => $e->globalPosition, $stored))->toBe([1, 2]);
});

<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Exception\StreamTypeMismatchException;
use Firefly\EventSourcing\Store\EventFilter;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\ExpectedVersion;
use Firefly\EventSourcing\Upcasting\CallbackEventUpcaster;
use Firefly\EventSourcing\Upcasting\UpcasterChain;

/**
 * What every EventStore owes its callers, written once and run against memory and SQLite in the default lane
 * and against PostgreSQL, MySQL, MariaDB and SQL Server in the integration lane. A case receives a factory, so
 * the upcasting case can build its store with a chain.
 */
final class EventStoreContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function event(string $type = 'order.placed', array $payload = ['orderId' => 'o-1'], ?EventMetadata $metadata = null, ?string $occurredAt = null): NewEvent
    {
        return new NewEvent($type, $payload, 1, $metadata ?? new EventMetadata, occurredAt: $occurredAt === null ? null : new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')));
    }

    /**
     * @param  list<StoredEvent>  $events
     * @return list<string>
     */
    public static function ids(array $events): array
    {
        return array_map(static fn (StoredEvent $event): string => $event->aggregateId, $events);
    }

    /** Builds the store under test through the case's factory, and refuses anything that is not an EventStore. */
    private static function store(Closure $make, ?UpcasterChain $upcasters = null): EventStore
    {
        $store = $make($upcasters);
        if (! $store instanceof EventStore) {
            throw new \LogicException('The contract factory must build an EventStore, got '.get_debug_type($store).'.');
        }

        return $store;
    }

    private static function conflict(Closure $append): ConcurrencyException
    {
        try {
            $append();
        } catch (ConcurrencyException $conflict) {
            return $conflict;
        }

        throw new \LogicException('The append was expected to conflict.');
    }

    /**
     * @return array<string, Closure(Closure(?UpcasterChain): EventStore): void>
     */
    public static function cases(): array
    {
        return [
            'appends and loads a stream in version order with its payload and metadata intact' => static function (Closure $make): void {
                $store = self::store($make);
                $stored = $store->append('order-1', 'order', [
                    self::event('order.placed', ['orderId' => 'order-1', 'lines' => [['sku' => 'A', 'qty' => 2]], 'note' => 'café'], new EventMetadata('corr-1', tenantId: 'acme'), '2026-10-13 10:00:00.123456'),
                    self::event('order.paid', ['amount' => 12.5]),
                ], ExpectedVersion::NO_STREAM);
                $stream = $store->load('order-1');

                expect(array_map(static fn (StoredEvent $e): int => $e->aggregateVersion, $stored))->toBe([1, 2])
                    ->and(array_map(static fn (StoredEvent $e): array => [$e->eventType, $e->aggregateVersion, $e->payload], $stream->events))->toBe([
                        ['order.placed', 1, ['orderId' => 'order-1', 'lines' => [['sku' => 'A', 'qty' => 2]], 'note' => 'café']],
                        ['order.paid', 2, ['amount' => 12.5]],
                    ])
                    ->and($stream->events[0]->metadata)->toEqual(new EventMetadata('corr-1', tenantId: 'acme'))
                    ->and($stream->events[0]->eventId)->toBe($stored[0]->eventId)
                    ->and($stream->events[0]->occurredAt->format('Y-m-d H:i:s.u'))->toBe('2026-10-13 10:00:00.123456')
                    ->and($stream->aggregateType())->toBe('order')
                    ->and($store->version('order-1'))->toBe(2)
                    ->and($store->exists('order-1'))->toBeTrue()
                    ->and($store->exists('order-2'))->toBeFalse()
                    ->and($store->version('order-2'))->toBe(0);
            },
            'refuses a stale expected version and leaves the stream as it was' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('order-1', 'order', [self::event(), self::event('order.paid')], 0);

                $conflict = self::conflict(static fn () => $store->append('order-1', 'order', [self::event('order.shipped')], 1));

                expect([$conflict->aggregateId, $conflict->expectedVersion, $conflict->actualVersion])->toBe(['order-1', 1, 2])
                    ->and($conflict->httpStatus())->toBe(409)
                    ->and($store->version('order-1'))->toBe(2)
                    ->and($store->load('order-1')->events)->toHaveCount(2);
            },
            'refuses an expected version ahead of the stream before writing anything' => static function (Closure $make): void {
                $store = self::store($make);

                $conflict = self::conflict(static fn () => $store->append('order-9', 'order', [self::event()], 5));

                expect([$conflict->expectedVersion, $conflict->actualVersion])->toBe([5, 0])
                    ->and($store->exists('order-9'))->toBeFalse();
            },
            'appends at the head with ANY and refuses NO_STREAM on a stream that exists' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('order-1', 'order', [self::event()], ExpectedVersion::ANY);
                $store->append('order-1', 'order', [self::event('order.paid')], ExpectedVersion::ANY);

                expect($store->version('order-1'))->toBe(2)
                    ->and(self::conflict(static fn () => $store->append('order-1', 'order', [self::event()], ExpectedVersion::NO_STREAM))->actualVersion)->toBe(2);
            },
            'refuses another aggregate type on an existing id' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('shared-1', 'order', [self::event()], 0);

                expect(fn () => $store->append('shared-1', 'invoice', [self::event('invoice.raised')], ExpectedVersion::ANY))
                    ->toThrow(StreamTypeMismatchException::class, 'shared-1');
            },
            'loads a slice of a stream between two versions' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('order-1', 'order', array_map(static fn (int $n): NewEvent => self::event('order.touched', ['n' => $n]), range(1, 5)), 0);

                expect(array_map(static fn (StoredEvent $e): int => $e->aggregateVersion, $store->load('order-1', 2, 4)->events))->toBe([2, 3, 4])
                    ->and($store->load('order-1', 6)->isEmpty())->toBeTrue();
            },
            'reads the global stream in commit order, paged after a position' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('a', 'order', [self::event(), self::event('order.paid')], 0);
                $store->append('b', 'order', [self::event()], 0);
                $store->append('a', 'order', [self::event('order.shipped')], 2);

                $all = $store->readAll(0, 100);
                $positions = array_map(static fn (StoredEvent $e): ?int => $e->globalPosition, $all);

                expect(self::ids($all))->toBe(['a', 'a', 'b', 'a'])
                    ->and($positions)->toBe([1, 2, 3, 4])
                    ->and(array_map(static fn (StoredEvent $e): ?int => $e->globalPosition, $store->readAll(2, 1)))->toBe([3])
                    ->and($store->readAll(4))->toBe([])
                    ->and($store->currentPosition())->toBe(4);
            },
            'filters the global stream by event type, aggregate type, time range and tenant' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('o-1', 'order', [self::event('order.placed', metadata: new EventMetadata(tenantId: 'acme'), occurredAt: '2026-10-13 09:00:00')], 0);
                $store->append('i-1', 'invoice', [self::event('invoice.raised', metadata: new EventMetadata(tenantId: 'globex'), occurredAt: '2026-10-13 10:00:00')], 0);
                $store->append('o-2', 'order', [self::event('order.paid', metadata: new EventMetadata(tenantId: 'acme'), occurredAt: '2026-10-13 11:00:00')], 0);

                expect(self::ids($store->readAll(0, 100, EventFilter::ofEventTypes('order.paid', 'invoice.raised'))))->toBe(['i-1', 'o-2'])
                    ->and(self::ids($store->readAll(0, 100, EventFilter::ofAggregateTypes('order'))))->toBe(['o-1', 'o-2'])
                    ->and(self::ids($store->readAll(0, 100, EventFilter::between(new DateTimeImmutable('2026-10-13 09:30:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-13 11:00:00', new DateTimeZone('UTC'))))))->toBe(['i-1'])
                    ->and(self::ids($store->readAll(0, 100, new EventFilter(tenantId: 'acme'))))->toBe(['o-1', 'o-2']);
            },
            'keeps tenants that differ only in case apart, as a tenant filter must be exact' => static function (Closure $make): void {
                $store = self::store($make);
                $store->append('o-1', 'order', [self::event(metadata: new EventMetadata(tenantId: 'Acme'))], 0);
                $store->append('o-2', 'order', [self::event(metadata: new EventMetadata(tenantId: 'acme'))], 0);

                expect(self::ids($store->readAll(0, 100, new EventFilter(tenantId: 'acme'))))->toBe(['o-2'])
                    ->and(self::ids($store->readAll(0, 100, new EventFilter(tenantId: 'Acme'))))->toBe(['o-1']);
            },
            'hands every read through the upcasters' => static function (Closure $make): void {
                $store = self::store($make, new UpcasterChain([new CallbackEventUpcaster('order.placed', 1, static fn (array $p): array => [...$p, 'currency' => 'EUR'])]));
                $store->append('order-1', 'order', [self::event('order.placed', ['total' => 5])], 0);

                $loaded = $store->load('order-1')->events[0];
                $read = $store->readAll()[0];

                expect([$loaded->eventVersion, $loaded->payload])->toBe([2, ['total' => 5, 'currency' => 'EUR']])
                    ->and([$read->eventVersion, $read->payload])->toBe([2, ['total' => 5, 'currency' => 'EUR']]);
            },
            'filters the global stream on the stored type, before a renaming upcaster runs' => static function (Closure $make): void {
                $store = self::store($make, new UpcasterChain([new CallbackEventUpcaster('order.created', 1, static fn (array $p): array => $p, toType: 'order.placed')]));
                $store->append('o-1', 'order', [self::event('order.created')], 0);
                $store->append('o-2', 'order', [self::event('order.placed')], 0);

                $byStoredName = $store->readAll(0, 100, EventFilter::ofEventTypes('order.created'));
                $byReadName = $store->readAll(0, 100, EventFilter::ofEventTypes('order.placed'));

                expect(self::ids($byStoredName))->toBe(['o-1'])
                    ->and(array_map(static fn (StoredEvent $e): array => [$e->eventType, $e->eventVersion], $byStoredName))->toBe([['order.placed', 2]])
                    ->and(self::ids($byReadName))->toBe(['o-2'])
                    ->and(array_map(static fn (StoredEvent $e): string => $e->eventType, $store->readAll(0, 100)))->toBe(['order.placed', 'order.placed']);
            },
            'treats an empty append as a no-op and reports itself healthy' => static function (Closure $make): void {
                $store = self::store($make);

                expect($store->append('order-1', 'order', [], 0))->toBe([])
                    ->and($store->exists('order-1'))->toBeFalse()
                    ->and($store->currentPosition())->toBe(0)
                    ->and($store->isHealthy())->toBeTrue();
            },
        ];
    }
}

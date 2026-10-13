<?php

declare(strict_types=1);

use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Exception\AggregateNotFoundException;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Metadata\MetadataResolver;
use Firefly\EventSourcing\Scanner\EventSourcingScanner;
use Firefly\EventSourcing\Snapshot\MemorySnapshotStore;
use Firefly\EventSourcing\Snapshot\Snapshot;
use Firefly\EventSourcing\Snapshot\SnapshotPolicy;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\EventFilter;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\EventStream;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Store\TenantResolver;
use Firefly\EventSourcing\Store\TenantScopedEventStore;
use Firefly\EventSourcing\Tests\Fixtures\Order;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Records the fromVersion of every load, so a test can see whether a snapshot was used. */
final class LoadRecordingStore implements EventStore
{
    /** @var list<int> */
    public array $loadsFrom = [];

    public function __construct(private readonly EventStore $inner = new MemoryEventStore) {}

    public function append(string $aggregateId, string $aggregateType, array $events, int $expectedVersion = -1): array
    {
        return $this->inner->append($aggregateId, $aggregateType, $events, $expectedVersion);
    }

    public function load(string $aggregateId, int $fromVersion = 1, ?int $toVersion = null): EventStream
    {
        $this->loadsFrom[] = $fromVersion;

        return $this->inner->load($aggregateId, $fromVersion, $toVersion);
    }

    public function version(string $aggregateId): int
    {
        return $this->inner->version($aggregateId);
    }

    public function exists(string $aggregateId): bool
    {
        return $this->inner->exists($aggregateId);
    }

    public function readAll(int $afterPosition = 0, int $limit = 500, ?EventFilter $filter = null): array
    {
        return $this->inner->readAll($afterPosition, $limit, $filter);
    }

    public function currentPosition(): int
    {
        return $this->inner->currentPosition();
    }

    public function isHealthy(): bool
    {
        return true;
    }
}

/** Keeps every log line as "level: message", so a test can see a warning was written. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = (is_string($level) ? $level : get_debug_type($level)).': '.$message;
    }
}

/** @return EventSourcedRepository<Order> */
function orders(EventStore $store, SnapshotStore $snapshots = new MemorySnapshotStore, int $threshold = 0, ?MetadataResolver $metadata = null, ?LoggerInterface $logger = null): EventSourcedRepository
{
    $mapper = new EventMapper(new EventTypeRegistry((new EventSourcingScanner)->scan(['Firefly\\EventSourcing\\Tests\\Fixtures\\Events\\' => dirname(__DIR__).'/Fixtures/Events'])->eventTypes()));

    return (new EventSourcingSupport($store, $mapper, $snapshots, new SnapshotPolicy(true, $threshold, 2), $metadata ?? new MetadataResolver, $logger ?? new NullLogger))->repository(Order::class);
}

it('saves an aggregate and finds it again at the version it was saved', function (): void {
    $repository = orders(new MemoryEventStore);
    $order = Order::place('o-1', 12.5);
    $order->pay(500);

    $stored = $repository->save($order);
    $found = $repository->get('o-1');

    expect(count($stored))->toBe(2)
        ->and([$found->total(), $found->paidCents(), $found->committedVersion()])->toBe([12.5, 500, 2])
        ->and($order->uncommittedEvents())->toBe([])
        ->and($repository->save($order))->toBe([])
        ->and($repository->exists('o-1'))->toBeTrue()
        ->and($repository->find('o-9'))->toBeNull()
        ->and(fn () => $repository->get('o-9'))->toThrow(AggregateNotFoundException::class, 'o-9');
});

it('refuses the second of two writers that loaded the same version', function (): void {
    $repository = orders(new MemoryEventStore);
    $repository->save(Order::place('o-1', 30.0));
    $first = $repository->get('o-1');
    $second = $repository->get('o-1');
    $first->pay(1000);
    $second->pay(2000);

    $repository->save($first);

    expect(fn () => $repository->save($second))->toThrow(ConcurrencyException::class)
        ->and($repository->get('o-1')->paidCents())->toBe(1000);
});

it('snapshots each time the version crosses the threshold, keeps two, and loads from the newest', function (): void {
    $store = new LoadRecordingStore;
    $snapshots = new MemorySnapshotStore;
    $repository = orders($store, $snapshots, 2);
    $order = Order::place('o-1', 100.0);
    $repository->save($order);
    foreach ([100, 200, 300, 400, 500] as $cents) {
        $order->pay($cents);
        $repository->save($order);
    }
    $store->loadsFrom = [];

    $found = $repository->get('o-1');

    expect($snapshots->latest('o-1')?->version)->toBe(6)
        ->and($snapshots->latest('o-1', 3)?->version)->toBeNull()
        ->and($store->loadsFrom)->toBe([7])
        ->and([$found->paidCents(), $found->committedVersion()])->toBe([1500, 6]);
});

it('replays from the first event when the snapshot was taken in another state shape', function (): void {
    $store = new LoadRecordingStore;
    $snapshots = new MemorySnapshotStore;
    $repository = orders($store, $snapshots, 0);
    $repository->save(Order::place('o-1', 10.0));
    $snapshots->save(new Snapshot('o-1', 'order', 1, ['total' => 999.0, 'paidCents' => 0], stateVersion: 99));

    expect($repository->get('o-1')->total())->toBe(10.0)
        ->and($store->loadsFrom)->toBe([1]);
});

it('stamps the resolved metadata and keeps the append when a snapshot cannot be written', function (): void {
    $broken = new class implements SnapshotStore
    {
        public function save(Snapshot $snapshot): void
        {
            throw new RuntimeException('snapshot disk full');
        }

        public function latest(string $aggregateId, ?int $atOrBeforeVersion = null): ?Snapshot
        {
            return null;
        }

        public function prune(string $aggregateId, int $keep): int
        {
            return 0;
        }

        public function delete(string $aggregateId): int
        {
            return 0;
        }

        public function isHealthy(): bool
        {
            return false;
        }
    };
    $logger = new RecordingLogger;
    $store = new MemoryEventStore;
    $repository = orders($store, $broken, 1, new MetadataResolver, $logger);

    $repository->save(Order::place('o-1', 5.0), new EventMetadata('corr-7'));

    expect($store->load('o-1')->events[0]->metadata->correlationId)->toBe('corr-7')
        ->and($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0])->toContain('warning: ')->toContain('o-1');
});

it('hides another tenant\'s aggregate even when a snapshot of it exists', function (): void {
    $tenants = new class implements TenantResolver
    {
        public ?string $tenant = 'acme';

        public function currentTenant(): ?string
        {
            return $this->tenant;
        }
    };
    $snapshots = new MemorySnapshotStore;
    $repository = orders(new TenantScopedEventStore(new MemoryEventStore, $tenants), $snapshots, 1);
    $repository->save(Order::place('o-1', 10.0));

    expect($snapshots->latest('o-1')?->version)->toBe(1);

    $tenants->tenant = 'globex';

    expect($repository->find('o-1'))->toBeNull()
        ->and($repository->exists('o-1'))->toBeFalse();
});

it('replays from the first event and logs a warning when the snapshot store cannot be read', function (): void {
    $unreadable = new class implements SnapshotStore
    {
        public function save(Snapshot $snapshot): void {}

        public function latest(string $aggregateId, ?int $atOrBeforeVersion = null): ?Snapshot
        {
            throw new RuntimeException('snapshot table missing');
        }

        public function prune(string $aggregateId, int $keep): int
        {
            return 0;
        }

        public function delete(string $aggregateId): int
        {
            return 0;
        }

        public function isHealthy(): bool
        {
            return false;
        }
    };
    $logger = new RecordingLogger;
    $store = new LoadRecordingStore;
    $repository = orders($store, $unreadable, 0, null, $logger);
    $repository->save(Order::place('o-1', 10.0));

    expect($repository->get('o-1')->total())->toBe(10.0)
        ->and($store->loadsFrom)->toBe([1])
        ->and($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0])->toContain('warning: ')->toContain('o-1')->toContain('snapshot table missing');
});

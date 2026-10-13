<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Projection\Checkpoint;
use Firefly\EventSourcing\Projection\CheckpointStore;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Projection\FunctionProjection;
use Firefly\EventSourcing\Projection\MemoryCheckpointStore;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Projection\ProjectionRunResult;
use Firefly\EventSourcing\Projection\ProjectionStatus;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Tests\Fixtures\RecordingInlineProjection;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\Scheduling\Lock\CacheLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/** A memory store with $events single-event streams: odd positions order.placed, even positions order.paid. */
function projectionRunnerStore(int $events): MemoryEventStore
{
    $store = new MemoryEventStore;
    for ($n = 1; $n <= $events; $n++) {
        $store->append('o-'.$n, 'order', [new NewEvent($n % 2 === 0 ? 'order.paid' : 'order.placed', ['n' => $n])], 0);
    }

    return $store;
}

/** @return list<int> */
function projectionRunnerTally(Connection $connection): array
{
    return array_values(array_map(static fn (mixed $position): int => is_numeric($position) ? (int) $position : 0, $connection->table('es_test_tally')->orderBy('position')->pluck('position')->all()));
}

it('catches up in batches, one checkpoint per batch, and moves past the event types it does not handle', function (): void {
    $seen = [];
    $projection = new FunctionProjection('placed', static function (StoredEvent $event) use (&$seen): void {
        $seen[] = $event->globalPosition;
    }, ['order.placed']);
    $checkpoints = new MemoryCheckpointStore;
    $runner = new ProjectionRunner(projectionRunnerStore(5), $checkpoints, new ProjectionRegistry([$projection]), batchSize: 2);

    $result = $runner->catchUp('placed');

    expect([$result->outcome, $result->fromPosition, $result->toPosition, $result->handled])->toBe([ProjectionRunResult::CAUGHT_UP, 0, 5, 3])
        ->and($seen)->toBe([1, 3, 5])
        ->and($checkpoints->get('placed')->position)->toBe(5)
        ->and($runner->lag('placed'))->toBe(0)
        ->and($runner->catchUp('placed')->handled)->toBe(0);
});

it('leaves a projection alone while another node holds its lease', function (): void {
    $cache = new Repository(new ArrayStore);
    $elsewhere = new CacheLock($cache, 'node-b');
    $elsewhere->tryAcquire(ProjectionRunner::LOCK_PREFIX.'placed', 30.0);
    $handled = 0;
    $projection = new FunctionProjection('placed', static function (StoredEvent $event) use (&$handled): void {
        $handled++;
    });
    $runner = new ProjectionRunner(projectionRunnerStore(3), new MemoryCheckpointStore, new ProjectionRegistry([$projection]), new CacheLock($cache, 'node-a'));

    $held = $runner->catchUp('placed');
    $elsewhere->release(ProjectionRunner::LOCK_PREFIX.'placed');
    $free = $runner->catchUp('placed');

    expect([$held->outcome, $held->handled])->toBe([ProjectionRunResult::LEASED_ELSEWHERE, 0])
        ->and([$free->outcome, $free->handled, $handled])->toBe([ProjectionRunResult::CAUGHT_UP, 3, 3]);
});

it('rolls a failing batch back with its rows, keeps the checkpoint, records the failure and retries next time', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $connection->getSchemaBuilder()->create('es_test_tally', static function (Blueprint $table): void {
        $table->bigInteger('position');
    });
    $store = new DatabaseEventStore($connection);
    foreach (range(1, 4) as $n) {
        $store->append('o-'.$n, 'order', [new NewEvent('order.placed', ['n' => $n])], 0);
    }
    // A switch the test flips between the two catch-ups (an object, so the closure sees the change).
    $outage = new class
    {
        public bool $down = true;
    };
    $projection = new FunctionProjection('tally', static function (StoredEvent $event) use ($connection, $outage): void {
        $connection->table('es_test_tally')->insert(['position' => $event->globalPosition]);
        if ($outage->down && $event->globalPosition === 3) {
            throw new RuntimeException('the tally is down');
        }
    });
    $checkpoints = new DatabaseCheckpointStore($connection);
    $runner = new ProjectionRunner($store, $checkpoints, new ProjectionRegistry([$projection]), batchSize: 2);

    $failed = $runner->catchUp('tally');
    $tallyAfterFailure = projectionRunnerTally($connection);
    $checkpointAfterFailure = $checkpoints->get('tally');
    $outage->down = false;
    $retried = $runner->catchUp('tally');

    expect([$failed->outcome, $failed->toPosition, $failed->handled, $failed->failed()])->toBe([ProjectionRunResult::FAILED, 2, 2, true])
        ->and($failed->error)->toContain('the tally is down')
        ->and($tallyAfterFailure)->toBe([1, 2])
        ->and([$checkpointAfterFailure->position, $checkpointAfterFailure->failed()])->toBe([2, true])
        ->and($checkpointAfterFailure->lastError)->toContain('RuntimeException: the tally is down')
        ->and([$retried->outcome, $retried->handled])->toBe([ProjectionRunResult::CAUGHT_UP, 2])
        ->and(projectionRunnerTally($connection))->toBe([1, 2, 3, 4])
        ->and($checkpoints->get('tally')->failed())->toBeFalse();
});

it('gives its batch up when another runner moved the checkpoint first (fencing)', function (): void {
    // A rival whose lease outlived ours advanced the checkpoint between our read and our commit.
    $rivalled = new class(new MemoryCheckpointStore) implements CheckpointStore
    {
        public function __construct(private readonly MemoryCheckpointStore $inner) {}

        public function get(string $projection): Checkpoint
        {
            return $this->inner->get($projection);
        }

        public function all(): array
        {
            return $this->inner->all();
        }

        public function advance(string $projection, int $from, int $to): bool
        {
            $this->inner->advance($projection, $from, $from + 1);

            return $this->inner->advance($projection, $from, $to);
        }

        public function reset(string $projection, int $position = 0): void
        {
            $this->inner->reset($projection, $position);
        }

        public function recordFailure(string $projection, string $error, DateTimeImmutable $at): void
        {
            $this->inner->recordFailure($projection, $error, $at);
        }

        public function transactional(Closure $work): mixed
        {
            return $work();
        }

        public function isHealthy(): bool
        {
            return true;
        }
    };
    $runner = new ProjectionRunner(projectionRunnerStore(3), $rivalled, new ProjectionRegistry([new FunctionProjection('p', static function (StoredEvent $event): void {})]));

    $result = $runner->catchUp('p');

    expect([$result->outcome, $result->handled, $result->toPosition])->toBe([ProjectionRunResult::FENCED, 0, 0])
        ->and($rivalled->get('p')->position)->toBe(1);
});

it('rebuilds from position 0 after a reset, and is the only way to run an inline projection', function (): void {
    $inline = new RecordingInlineProjection;
    $runner = new ProjectionRunner(projectionRunnerStore(3), new MemoryCheckpointStore, new ProjectionRegistry([$inline]));

    expect(fn () => $runner->catchUp('recorded.inline'))->toThrow(EventSourcingException::class, 'is inline')
        ->and($runner->catchUpAll())->toBe([]);

    $inline->positions = [99];
    $rebuilt = $runner->rebuild('recorded.inline');

    expect([$rebuilt->outcome, $rebuilt->handled, $inline->resets, $inline->positions])->toBe([ProjectionRunResult::CAUGHT_UP, 3, 1, [1, 2, 3]])
        ->and($runner->statuses()[0]->state())->toBe('inline')
        ->and($runner->lag('recorded.inline'))->toBe(0);
});

it('follows the stream in live mode, sleeping the poll interval only when idle, until told to stop', function (): void {
    $store = projectionRunnerStore(2);
    $seen = [];
    $sleeps = [];
    $projection = new FunctionProjection('all', static function (StoredEvent $event) use (&$seen): void {
        $seen[] = $event->globalPosition;
    });
    $runner = new ProjectionRunner($store, new MemoryCheckpointStore, new ProjectionRegistry([$projection]), pollInterval: 0.25, sleep: static function (float $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });
    $checks = 0;

    $cycles = $runner->runLive(shouldStop: static function () use (&$checks, $store): bool {
        $checks++;
        if ($checks === 3) {
            $store->append('o-late', 'order', [new NewEvent('order.placed', ['n' => 3])], 0);
        }

        return $checks > 4;
    });

    expect($cycles)->toBe(4)
        ->and($seen)->toBe([1, 2, 3])
        ->and($sleeps)->toBe([0.25, 0.25])
        ->and($runner->runLive(maxRuntime: 0.0))->toBe(0);
});

it('reports each projection\'s position, the head and its lag', function (): void {
    $registry = new ProjectionRegistry([
        new FunctionProjection('b', static function (StoredEvent $event): void {}),
        new FunctionProjection('a', static function (StoredEvent $event): void {}),
    ]);
    $runner = new ProjectionRunner(projectionRunnerStore(4), new MemoryCheckpointStore, $registry, batchSize: 3);
    $runner->catchUp('a');

    expect(array_map(static fn (ProjectionStatus $s): array => [$s->name, $s->position, $s->head, $s->lag, $s->state()], $runner->statuses()))
        ->toBe([['a', 4, 4, 0, 'ok'], ['b', 0, 4, 4, 'ok']])
        ->and($runner->lag('b'))->toBe(4)
        ->and(array_map(static fn (ProjectionRunResult $r): string => $r->projection.':'.$r->outcome, $runner->catchUpAll()))->toBe(['a:caught-up', 'b:caught-up']);
});

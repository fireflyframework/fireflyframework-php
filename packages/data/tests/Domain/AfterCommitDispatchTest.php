<?php

declare(strict_types=1);

use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Tests\Fixtures\Domain\Basket;
use Firefly\Data\Tests\Fixtures\Domain\BasketRepository;
use Firefly\Data\Tests\Fixtures\Domain\ItemAdded;
use Firefly\Data\Tests\Fixtures\Domain\NoteAdded;
use Firefly\Data\Tests\Fixtures\Domain\SecondaryNote;
use Firefly\Data\Tests\Fixtures\Domain\SecondaryNoteRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

/**
 * Builds a real tracker + spy publisher + dispatcher + a template WIRED with that dispatcher — the exact object
 * graph DataAutoConfiguration assembles, over the real sqlite connection.
 *
 * @return array{0: TransactionTemplate, 1: AggregateTracker, 2: RecordingApplicationEventPublisher}
 */
function txStack(): array
{
    $tracker = new AggregateTracker;
    $spy = new RecordingApplicationEventPublisher;
    $dispatcher = new DomainEventDispatcher($tracker, $spy);
    $template = new TransactionTemplate($dispatcher);

    return [$template, $tracker, $spy];
}

/**
 * Configures the SECOND, NON-default sqlite :memory: connection ('secondary') + its `notes` schema, used only by
 * the non-default-connection coverage tests below. Configured at runtime (not in defineEnvironment) so it stays
 * local to this file and does not burden every other data test with a connection/table it never needs.
 */
function configureSecondaryConnection(): void
{
    config(['database.connections.secondary' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);

    Schema::connection('secondary')->create('notes', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('text');
    });
}

/**
 * Narrow a spied `object` to the concrete ItemAdded type before reading its ->sku — same instanceof-narrow-or-throw
 * shape as ApplicationEventPublisherTest's port test (no @var/@phpstan-var override, no cast).
 */
function asItemAdded(object $event): ItemAdded
{
    if (! $event instanceof ItemAdded) {
        throw new RuntimeException('Expected an ItemAdded event.');
    }

    return $event;
}

/**
 * Same narrowing for the NON-default-connection fixture's event.
 */
function asNoteAdded(object $event): NoteAdded
{
    if (! $event instanceof NoteAdded) {
        throw new RuntimeException('Expected a NoteAdded event.');
    }

    return $event;
}

it('publishes a tracked aggregate event ONLY after the transaction commits', function () {
    [$template, $tracker, $spy] = txStack();

    $basket = new Basket;
    $basket->addItem('milk');

    $template->execute(function () use ($tracker, $basket, $spy): void {
        $tracker->track($basket);
        DB::table('widgets')->insert(['name' => 'w']);
        expect($spy->events)->toBe([]); // still inside the tx — nothing published yet
    });

    expect($spy->events)->toHaveCount(1)
        ->and($spy->events[0])->toBeInstanceOf(ItemAdded::class)
        ->and($tracker->tracked())->toBe([]); // drained on commit
});

it('registers a saved aggregate through EloquentRepository::save during an active transaction', function () {
    [$template, $tracker, $spy] = txStack();
    $repo = new BasketRepository(null, $tracker);

    $basket = new Basket;
    $basket->addItem('bread');

    $template->execute(function () use ($repo, $basket, $spy): void {
        $repo->save($basket);            // base save() tracks it (aggregate + active tx)
        expect($spy->events)->toBe([]);
    });

    expect($spy->events)->toHaveCount(1);
    expect(asItemAdded($spy->events[0])->sku)->toBe('bread');
});

it('does not track (and so cannot leak) an aggregate saved OUTSIDE an active transaction', function () {
    [$template, $tracker, $spy] = txStack();
    $repo = new BasketRepository(null, $tracker);

    $outside = new Basket;
    $outside->addItem('outside');
    $repo->save($outside); // NO active transaction — transactionLevel() > 0 guard must reject tracking

    expect($tracker->tracked())->toBe([]);

    $inside = new Basket;
    $inside->addItem('inside');
    $template->execute(function () use ($repo, $inside): void {
        $repo->save($inside);
    });

    // Only the aggregate saved INSIDE the transaction publishes — the outside-tx save left nothing to leak.
    expect($spy->events)->toHaveCount(1);
    expect(asItemAdded($spy->events[0])->sku)->toBe('inside');
});

it('discards events when the unit of work rolls back (no publish, no leak)', function () {
    [$template, $tracker, $spy] = txStack();

    $basket = new Basket;
    $basket->addItem('never');

    try {
        $template->execute(function () use ($tracker, $basket): void {
            $tracker->track($basket);
            DB::table('widgets')->insert(['name' => 'gone']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($spy->events)->toBe([])          // DB::afterCommit callbacks discarded on rollBack
        ->and($tracker->tracked())->toBe([]) // drained → no leak
        ->and(DB::table('widgets')->count())->toBe(0);
});

it('publishes multiple events in the order they were raised', function () {
    [$template, $tracker, $spy] = txStack();

    $basket = new Basket;
    $basket->addItem('first');
    $basket->addItem('second');

    $template->execute(fn () => $tracker->track($basket));

    $skus = array_map(static fn (object $event): string => asItemAdded($event)->sku, $spy->events);

    expect($skus)->toBe(['first', 'second']);
});

it('clears the tracker between units of work (no event leak across transactions)', function () {
    [$template, $tracker, $spy] = txStack();

    $first = new Basket;
    $first->addItem('a');
    $template->execute(fn () => $tracker->track($first));
    expect($spy->events)->toHaveCount(1);

    $second = new Basket;
    $second->addItem('b');
    $template->execute(fn () => $tracker->track($second));

    // Exactly ONE more event (b) — the first unit's aggregate must not re-publish.
    expect($spy->events)->toHaveCount(2);
    expect(asItemAdded($spy->events[1])->sku)->toBe('b');
    expect($tracker->tracked())->toBe([]);
});

it('publishAfterCommit publishes an explicitly-handed aggregate only after commit', function () {
    [$template, , $spy] = txStack();
    $dispatcher = new DomainEventDispatcher(new AggregateTracker, $spy);

    $basket = new Basket;
    $basket->addItem('explicit');

    $template->execute(function () use ($dispatcher, $basket, $spy): void {
        $dispatcher->publishAfterCommit($basket);
        expect($spy->events)->toBe([]);      // queued via DB::afterCommit, not yet fired
    });

    expect($spy->events)->toHaveCount(1);
    expect(asItemAdded($spy->events[0])->sku)->toBe('explicit');
});

// --- REQUIRED — non-default-connection coverage (the FIX-2 correctness property) ---
//
// Every test above runs on the DatabaseTestCase's single ('testing') default connection, so they would all still
// pass even if the dispatcher/save() guard hard-coded the default connection. These two tests use a SEPARATE
// sqlite :memory: connection ('secondary') via a Model that implements RecordsDomainEvents (SecondaryNote), so
// BOTH save()'s transactionLevel() guard (keyed off the entity's OWN connection) and the dispatcher's
// DB::connection($connection)->afterCommit(...) registration are exercised off the NON-default connection name.

it('dispatches after commit on a NON-default connection', function () {
    configureSecondaryConnection();

    [$template, $tracker, $spy] = txStack();
    $repo = new SecondaryNoteRepository(null, $tracker);

    $note = new SecondaryNote(['text' => 'hello']);
    $note->addNote('hello');

    $template->execute(function () use ($repo, $note, $spy): void {
        $repo->save($note); // persists on 'secondary' AND tracks (transactionLevel() > 0 on 'secondary')
        expect($spy->events)->toBe([]); // still inside the 'secondary' tx — nothing published yet
    }, new TransactionalDescriptor(connection: 'secondary'));

    expect($spy->events)->toHaveCount(1);
    expect(asNoteAdded($spy->events[0])->text)->toBe('hello');
    expect($tracker->tracked())->toBe([])
        ->and(DB::connection('secondary')->table('notes')->count())->toBe(1);
});

it('discards events when a unit of work on a NON-default connection rolls back', function () {
    configureSecondaryConnection();

    [$template, $tracker, $spy] = txStack();
    $repo = new SecondaryNoteRepository(null, $tracker);

    $note = new SecondaryNote(['text' => 'never']);
    $note->addNote('never');

    try {
        $template->execute(function () use ($repo, $note): void {
            $repo->save($note);
            throw new RuntimeException('boom');
        }, new TransactionalDescriptor(connection: 'secondary'));
    } catch (RuntimeException) {
    }

    expect($spy->events)->toBe([])
        ->and($tracker->tracked())->toBe([])
        ->and(DB::connection('secondary')->table('notes')->count())->toBe(0);
});

<?php

declare(strict_types=1);

use Firefly\EventSourcing\Projection\CheckpointStore;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\CapstoneFixtures\OrderRepository;
use Firefly\EventSourcing\Tests\CapstoneFixtures\OrderTotalsProjection;
use Firefly\EventSourcing\Tests\Fixtures\Order;
use Firefly\EventSourcing\Tests\Support\EventSourcingCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(EventSourcingCapstoneTestCase::class);

it('discovers a projection bean and runs it to the head with firefly:eventsourcing:projections:run', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    $orders = $this->app()->make(OrderRepository::class);
    $order = Order::place('proj-1', 12.5);
    $order->pay(250);
    $orders->save($order);
    $orders->save(Order::place('proj-2', 3.0));

    $exit = $this->command('firefly:eventsourcing:projections:run');

    expect($exit)->toBe(0)
        ->and($this->commandOutput())->toContain('order.totals: caught-up, 3 event(s) handled, at position 3')
        ->and($this->app()->make(ProjectionRegistry::class)->names())->toBe(['order.totals'])
        ->and($this->app()->make(OrderTotalsProjection::class)->orders)->toBe(['proj-1' => ['total' => 12.5, 'paidCents' => 250], 'proj-2' => ['total' => 3.0, 'paidCents' => 0]])
        ->and($this->app()->make(CheckpointStore::class))->toBeInstanceOf(DatabaseCheckpointStore::class)
        ->and(DB::table(EventSourcingSchema::CHECKPOINTS)->where('projection', 'order.totals')->value('position'))->toEqual(3);
});

it('lists every projection with its position, the head and its lag', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    $this->app()->make(OrderRepository::class)->save(Order::place('proj-3', 1.0));

    $this->command('firefly:eventsourcing:projections');
    $before = $this->commandOutput();
    $this->command('firefly:eventsourcing:projections:run', ['projection' => 'order.totals']);
    $this->command('firefly:eventsourcing:projections');
    $after = $this->commandOutput();

    expect($before)->toMatch('/order\.totals\s*\|\s*catch-up\s*\|\s*0\s*\|\s*1\s*\|\s*1\s*\|\s*ok/')
        ->and($after)->toMatch('/order\.totals\s*\|\s*catch-up\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*ok/');
});

it('rebuilds a projection from position 0 with firefly:eventsourcing:projections:rebuild', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    $this->app()->make(OrderRepository::class)->save(Order::place('proj-4', 9.0));
    $this->command('firefly:eventsourcing:projections:run');
    $projection = $this->app()->make(OrderTotalsProjection::class);
    $projection->orders['ghost'] = ['total' => 1.0, 'paidCents' => 0];

    $exit = $this->command('firefly:eventsourcing:projections:rebuild', ['projection' => 'order.totals']);

    expect($exit)->toBe(0)
        ->and($this->commandOutput())->toContain('order.totals rebuilt: 1 event(s) handled, at position 1')
        ->and($projection->resets)->toBe(1)
        ->and($projection->orders)->toBe(['proj-4' => ['total' => 9.0, 'paidCents' => 0]]);
});

it('refuses a projection it does not know, and runs live until --max-runtime', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    expect($this->command('firefly:eventsourcing:projections:rebuild', ['projection' => 'nope']))->toBe(1)
        ->and($this->commandOutput())->toContain('No projection is named [nope]')
        ->and($this->command('firefly:eventsourcing:projections:run', ['--live' => true, '--max-runtime' => '0.2']))->toBe(0)
        ->and($this->commandOutput())->toContain('Stopped after');
});

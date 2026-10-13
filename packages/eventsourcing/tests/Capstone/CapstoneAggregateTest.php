<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Tests\CapstoneFixtures\OrderRepository;
use Firefly\EventSourcing\Tests\Fixtures\Order;
use Firefly\EventSourcing\Tests\Support\EventSourcingCapstoneTestCase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

uses(EventSourcingCapstoneTestCase::class);

it('saves and reloads an aggregate through the real beans into the database, snapshotting every two events', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    $orders = $this->app()->make(OrderRepository::class);
    $order = Order::place('cap-1', 40.0);
    $orders->save($order);
    $order->pay(1000);
    $order->pay(500);
    $orders->save($order);

    $found = $orders->get('cap-1');

    expect($this->app()->make(EventStore::class))->toBeInstanceOf(DatabaseEventStore::class)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'cap-1')->count())->toBe(3)
        ->and($this->app()->make(SnapshotStore::class)->latest('cap-1')?->version)->toBe(3)
        ->and([$found->total(), $found->paidCents(), $found->committedVersion()])->toBe([40.0, 1500, 3]);
});

it('upcasts an old event version through an upcaster bean when the aggregate loads', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    $this->app()->make(EventStore::class)->append('cap-legacy', 'order', [new NewEvent('order.placed', ['orderId' => 'cap-legacy', 'amount' => 7], 1)], 0);

    expect($this->app()->make(OrderRepository::class)->get('cap-legacy')->total())->toBe(7.0);
});

it('refuses a stale writer with a 409 ConcurrencyException', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    $orders = $this->app()->make(OrderRepository::class);
    $orders->save(Order::place('cap-2', 10.0));
    $stale = $orders->get('cap-2');
    $fresh = $orders->get('cap-2');
    $fresh->pay(100);
    $orders->save($fresh);
    $stale->pay(200);

    expect(fn () => $orders->save($stale))->toThrow(ConcurrencyException::class);
});

it('stamps the correlation id of the request context into every event', function (): void {
    /** @var EventSourcingCapstoneTestCase $this */
    Context::add('firefly.correlation_id', 'corr-capstone');
    $this->app()->make(OrderRepository::class)->save(Order::place('cap-3', 1.0));

    expect($this->app()->make(EventStore::class)->load('cap-3')->events[0]->metadata->correlationId)->toBe('corr-capstone')
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'cap-3')->value('correlation_id'))->toBe('corr-capstone');
});

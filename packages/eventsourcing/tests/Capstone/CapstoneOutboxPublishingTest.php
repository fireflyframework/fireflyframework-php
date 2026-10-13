<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Publishing\EventStorePublisher;
use Firefly\EventSourcing\Publishing\OutboxEventStorePublisher;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Tests\CapstoneFixtures\OrderRepository;
use Firefly\EventSourcing\Tests\Fixtures\Order;
use Firefly\EventSourcing\Tests\Support\OutboxPublishingCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(OutboxPublishingCapstoneTestCase::class);

it('writes exactly one outbox row per appended event, in the append\'s transaction', function (): void {
    /** @var OutboxPublishingCapstoneTestCase $this */
    $orders = $this->app()->make(OrderRepository::class);
    $order = Order::place('pub-1', 20.0);
    $orders->save($order);
    $order->pay(500);
    $orders->save($order);

    expect($this->app()->make(EventStorePublisher::class))->toBeInstanceOf(OutboxEventStorePublisher::class)
        ->and(DB::table(OutboxSchema::TABLE)->orderBy('id')->pluck('event_type')->all())->toBe(['order.placed', 'order.paid'])
        ->and(DB::table(OutboxSchema::TABLE)->distinct()->pluck('destination')->all())->toBe(['orders'])
        ->and(DB::table(EventSourcingSchema::EVENTS)->count())->toBe(2);
});

it('rolls the outbox rows back with the events, and never publishes an excluded aggregate type', function (): void {
    /** @var OutboxPublishingCapstoneTestCase $this */
    $orders = $this->app()->make(OrderRepository::class);
    try {
        DB::transaction(static function () use ($orders): void {
            $orders->save(Order::place('pub-2', 5.0));
            throw new RuntimeException('the command failed after saving');
        });
    } catch (RuntimeException) {
    }
    $this->app()->make(EventStore::class)->append('exec-1', 'orchestration.execution', [new NewEvent('orchestration.execution.started', ['state' => '{}'])], 0);

    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(0)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'pub-2')->count())->toBe(0)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'exec-1')->count())->toBe(1);
});

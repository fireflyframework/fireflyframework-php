<?php

declare(strict_types=1);

use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\CapstoneFixtures\OrderRepository;
use Firefly\EventSourcing\Tests\Fixtures\Order;
use Firefly\EventSourcing\Tests\Support\ActuatorEventSourcingCapstoneTestCase;
use Illuminate\Support\Facades\Schema;

uses(ActuatorEventSourcingCapstoneTestCase::class);

it('reports the eventsourcing component with the global position and every projection\'s lag', function (): void {
    /** @var ActuatorEventSourcingCapstoneTestCase $this */
    $this->app()->make(OrderRepository::class)->save(Order::place('h-1', 1.0));
    $this->command('firefly:eventsourcing:projections:run');

    $health = $this->getJson('/actuator/health');

    expect($health->json('components.eventsourcing.status'))->toBe('UP')
        ->and($health->json('components.eventsourcing.details'))->toBe([
            'store' => 'database',
            'position' => 1,
            'projections' => ['order.totals' => ['position' => 1, 'lag' => 0, 'status' => 'ok']],
        ]);
});

it('turns DOWN when a catch-up projection lags more than max-lag', function (): void {
    /** @var ActuatorEventSourcingCapstoneTestCase $this */
    $orders = $this->app()->make(OrderRepository::class);
    foreach (['h-2', 'h-3', 'h-4'] as $id) {
        $orders->save(Order::place($id, 1.0));
    }

    $health = $this->getJson('/actuator/health');

    expect($health->json('components.eventsourcing.status'))->toBe('DOWN')
        ->and($health->json('components.eventsourcing.details.projections'))->toBe(['order.totals' => ['position' => 0, 'lag' => 3, 'status' => 'ok']])
        ->and($health->json('components.eventsourcing.details.reason'))->toContain('order.totals (3)');
});

it('turns DOWN when the event store does not answer', function (): void {
    /** @var ActuatorEventSourcingCapstoneTestCase $this */
    Schema::drop(EventSourcingSchema::EVENTS);

    $health = $this->getJson('/actuator/health');

    expect($health->json('components.eventsourcing.status'))->toBe('DOWN')
        ->and($health->json('components.eventsourcing.details'))->toBe(['store' => 'database', 'error' => 'the event store does not answer']);
});

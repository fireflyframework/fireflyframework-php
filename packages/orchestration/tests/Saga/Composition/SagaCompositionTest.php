<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Saga\Composition\CompositionDataFlowManager;
use Firefly\Orchestration\Saga\Composition\DataMapping;
use Firefly\Orchestration\Saga\Composition\SagaCompositionBuilder;

it('layers members by dependsOn and keeps their inputs and conditions', function (): void {
    $composition = SagaCompositionBuilder::composition('checkout')
        ->saga('stock')->sagaName('reserve-stock')->input('warehouse', 'eu-1')->add()
        ->saga('charge')->sagaName('charge-card')->dependsOn('stock')->add()
        ->saga('notify')->sagaName('notify-customer')->dependsOn('charge')->optional()->condition('input["notify"] == true')->add()
        ->dataFlow('stock', 'reservationId', 'charge', 'reservationId')
        ->build();

    expect($composition->layers)->toBe([['stock'], ['charge'], ['notify']])
        ->and($composition->sagas['stock']->staticInput)->toBe(['warehouse' => 'eu-1'])
        ->and($composition->sagas['notify']->optional)->toBeTrue()
        ->and($composition->sagas['notify']->condition)->toBe('input["notify"] == true')
        ->and($composition->dataMappings)->toEqual([new DataMapping('stock', 'reservationId', 'charge', 'reservationId')]);
});

it('refuses a cycle and a data flow naming an unknown member', function (): void {
    expect(fn () => SagaCompositionBuilder::composition('loop')
        ->saga('a')->sagaName('x')->dependsOn('b')->add()
        ->saga('b')->sagaName('y')->dependsOn('a')->add()
        ->build())->toThrow(TopologyValidationException::class, 'Saga composition [loop]: the steps form a cycle')
        ->and(fn () => SagaCompositionBuilder::composition('flow')
            ->saga('a')->sagaName('x')->add()
            ->dataFlow('ghost', 'id', 'a', 'id')
            ->build())->toThrow(TopologyValidationException::class, 'Saga composition [flow] maps data from [ghost], which is not a member of it.');
});

it('flows a field found inside a step result, or a whole step result, into the next member', function (): void {
    $manager = new CompositionDataFlowManager;
    $mappings = [new DataMapping('stock', 'reservationId', 'charge', 'reservation'), new DataMapping('stock', 'audit', 'charge', 'audit')];

    expect($manager->resolveInputs(['amount' => 5, 'warehouse' => 'x'], ['warehouse' => 'eu-1'], $mappings, 'charge', [
        'stock' => ['reserve' => ['reservationId' => 'r-1'], 'audit' => 'logged'],
    ]))->toBe(['amount' => 5, 'warehouse' => 'eu-1', 'reservation' => 'r-1', 'audit' => 'logged']);
});

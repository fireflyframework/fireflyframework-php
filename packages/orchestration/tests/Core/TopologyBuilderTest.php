<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\Topology\TopologyBuilder;

it('layers a diamond and keeps declaration order inside a layer', function (): void {
    $layers = TopologyBuilder::layers('Saga [order]', [
        'reserve' => [],
        'charge' => ['reserve'],
        'notify' => ['reserve'],
        'ship' => ['charge', 'notify'],
        'audit' => [],
    ]);

    expect($layers)->toBe([['reserve', 'audit'], ['charge', 'notify'], ['ship']]);
});

it('keeps numeric step ids as strings', function (): void {
    expect(TopologyBuilder::layers('Saga [n]', ['1' => [], '2' => ['1']]))->toBe([['1'], ['2']]);
});

it('refuses an empty definition, an unknown dependency and a self dependency', function (): void {
    expect(fn () => TopologyBuilder::layers('Saga [s]', []))
        ->toThrow(TopologyValidationException::class, 'Saga [s] declares no steps.')
        ->and(fn () => TopologyBuilder::layers('Saga [s]', ['a' => ['ghost']]))
        ->toThrow(TopologyValidationException::class, 'step [a] depends on [ghost], which is not a step of it')
        ->and(fn () => TopologyBuilder::layers('Saga [s]', ['a' => ['a']]))
        ->toThrow(TopologyValidationException::class, 'step [a] depends on itself');
});

it('refuses a cycle and names its path', function (): void {
    TopologyBuilder::layers('Workflow [w]', ['a' => ['c'], 'b' => ['a'], 'c' => ['b']]);
})->throws(TopologyValidationException::class, 'Workflow [w]: the steps form a cycle: a -> c -> b -> a.');

<?php

declare(strict_types=1);

use Firefly\Admin\BeanGraph;

/**
 * fromCatalog() takes the catalogue as BeansCatalog publishes it — array<mixed>, because the endpoint's rows
 * are whatever the manifest held. The malformed-row test below depends on being able to pass junk.
 *
 * @param  array<mixed>  $beans
 */
function graphOf(array $beans): BeanGraph
{
    return BeanGraph::fromCatalog($beans);
}

/**
 * @param  list<string>  $dependencies
 * @param  list<string>  $interfaces
 * @return array<string,mixed>
 */
function bean(string $class, array $dependencies = [], array $interfaces = []): array
{
    return [
        'class' => $class,
        'stereotype' => 'service',
        'scope' => 'Singleton',
        'name' => null,
        'interfaces' => $interfaces,
        'beans' => [],
        'dependencies' => $dependencies,
    ];
}

it('links a dependency on a concrete class straight to that bean', function () {
    $graph = graphOf([bean('App\\Controller', ['App\\Service']), bean('App\\Service')]);

    expect($graph->edges)->toBe([['from' => 'App\\Controller', 'to' => 'App\\Service', 'via' => null]]);
});

// The reason a naive edge list produces a field of disconnected dots: constructors ask for INTERFACES, and
// the bean that satisfies one is a concrete class with a different name.
it('resolves a dependency on an interface to the bean that implements it', function () {
    $graph = graphOf([
        bean('App\\Publisher', ['App\\Contracts\\Transport']),
        bean('App\\KafkaTransport', [], ['App\\Contracts\\Transport']),
    ]);

    expect($graph->edges)->toBe([
        ['from' => 'App\\Publisher', 'to' => 'App\\KafkaTransport', 'via' => 'App\\Contracts\\Transport'],
    ]);
});

it('reports a type nothing provides instead of dropping it silently', function () {
    $graph = graphOf([bean('App\\Controller', ['Illuminate\\Http\\Request'])]);

    expect($graph->edges)->toBe([])
        ->and($graph->unresolved)->toBe(['Illuminate\\Http\\Request']);
});

it('never draws a self-edge', function () {
    $graph = graphOf([bean('App\\Recursive', ['App\\Recursive'])]);

    expect($graph->edges)->toBe([]);
});

it('de-duplicates repeated relations between the same pair', function () {
    $graph = graphOf([
        bean('App\\A', ['App\\B', 'App\\Contracts\\B'], []),
        bean('App\\B', [], ['App\\Contracts\\B']),
    ]);

    expect($graph->edges)->toHaveCount(1);
});

// A cycle must terminate and be REPORTED — the container has no cycle detection, so a cycle among eager
// singletons exhausts memory at boot, and naming it is the most useful thing this page can do.
it('terminates on a cycle and reports the edge that closed it', function () {
    $graph = graphOf([bean('App\\A', ['App\\B']), bean('App\\B', ['App\\A'])]);

    expect($graph->cycles)->not->toBeEmpty()
        ->and($graph->nodes)->toHaveCount(2);
});

it('layers so a dependency always sits below what depends on it', function () {
    $graph = graphOf([
        bean('App\\Controller', ['App\\Service']),
        bean('App\\Service', ['App\\Repository']),
        bean('App\\Repository'),
    ]);

    $level = [];
    foreach ($graph->nodes as $node) {
        $level[$node['id']] = $node['level'];
    }

    expect($level['App\\Controller'])->toBeLessThan($level['App\\Service'])
        ->and($level['App\\Service'])->toBeLessThan($level['App\\Repository']);
});

it('counts in and out degree per bean', function () {
    $graph = graphOf([
        bean('App\\A', ['App\\C']),
        bean('App\\B', ['App\\C']),
        bean('App\\C'),
    ]);

    $byId = [];
    foreach ($graph->nodes as $node) {
        $byId[$node['id']] = $node;
    }

    expect($byId['App\\C']['in'])->toBe(2)
        ->and($byId['App\\C']['out'])->toBe(0)
        ->and($byId['App\\A']['out'])->toBe(1);
});

it('ignores malformed catalogue rows rather than failing the page', function () {
    $graph = graphOf([bean('App\\Ok'), ['no-class' => true], ['class' => 42]]);

    expect($graph->nodes)->toHaveCount(1)
        ->and($graph->nodes[0]['id'])->toBe('App\\Ok');
});

it('splits a class into a short label and its namespace for the drawing', function () {
    $graph = graphOf([bean('App\\Domain\\OrderService')]);

    expect($graph->nodes[0]['label'])->toBe('OrderService')
        ->and($graph->nodes[0]['namespace'])->toBe('App\\Domain');
});

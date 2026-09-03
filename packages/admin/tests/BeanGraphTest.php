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

    expect($graph->edges)->toBe([
        ['from' => 'App\\Controller', 'to' => 'App\\Service', 'via' => null, 'type' => BeanGraph::EDGE_INJECTS],
    ]);
});

// The reason a naive edge list produces a field of disconnected dots: constructors ask for INTERFACES, and
// the bean that satisfies one is a concrete class with a different name.
it('resolves a dependency on an interface to the bean that implements it', function () {
    $graph = graphOf([
        bean('App\\Publisher', ['App\\Contracts\\Transport']),
        bean('App\\KafkaTransport', [], ['App\\Contracts\\Transport']),
    ]);

    expect($graph->edges)->toBe([
        ['from' => 'App\\Publisher', 'to' => 'App\\KafkaTransport', 'via' => 'App\\Contracts\\Transport', 'type' => BeanGraph::EDGE_INJECTS],
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

// ─────────────────────────────────────────────────────────────────────────────────────────────────────
// #[Bean] PRODUCTS. The graph's original blind spot: a framework's wiring lives almost entirely in
// #[Configuration] classes whose #[Bean] methods produce the collaborators everything else injects. Only
// declaring classes were nodes, so on a stock skeleton 41 of 42 relations pointed at nodes that did not
// exist and exactly ONE edge was drawn.
// ─────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * @param  list<array{type: string, method: string, dependencies: list<string>}>  $produces
 * @param  list<string>  $dependencies
 * @return array<string,mixed>
 */
function configuration(string $class, array $produces, array $dependencies = []): array
{
    return [
        'class' => $class,
        'stereotype' => 'configuration',
        'scope' => 'Singleton',
        'name' => null,
        'interfaces' => [],
        'beans' => array_map(static fn (array $p): string => $p['method'], $produces),
        'dependencies' => $dependencies,
        'produces' => $produces,
    ];
}

it('makes every #[Bean] product a node of its own', function () {
    $graph = BeanGraph::build([
        configuration('App\\Config', [
            ['type' => 'App\\MeterRegistry', 'method' => 'meters', 'dependencies' => []],
            ['type' => 'App\\Tracer', 'method' => 'tracer', 'dependencies' => []],
        ]),
    ]);

    $ids = array_column($graph->nodes, 'id');

    expect($ids)->toContain('App\\MeterRegistry')
        ->and($ids)->toContain('App\\Tracer')
        ->and($graph->kindCounts()[BeanGraph::KIND_BEAN])->toBe(2)
        ->and($graph->kindCounts()[BeanGraph::KIND_COMPONENT])->toBe(1);
});

it('draws a produces edge from the configuration to each product', function () {
    $graph = BeanGraph::build([
        configuration('App\\Config', [['type' => 'App\\MeterRegistry', 'method' => 'meters', 'dependencies' => []]]),
    ]);

    expect($graph->edges)->toBe([
        ['from' => 'App\\Config', 'to' => 'App\\MeterRegistry', 'via' => null, 'type' => BeanGraph::EDGE_PRODUCES],
    ]);
});

// The whole point: a component injecting a type that a factory produces must LINK to it. This is the case
// that produced 21 dangling dependencies before #[Bean] products became nodes.
it('links a consumer to the #[Bean] product it injects', function () {
    $graph = BeanGraph::build([
        bean('App\\Filter', ['App\\MeterRegistry']),
        configuration('App\\Config', [['type' => 'App\\MeterRegistry', 'method' => 'meters', 'dependencies' => []]]),
    ]);

    $injects = array_values(array_filter($graph->edges, static fn (array $e): bool => $e['type'] === BeanGraph::EDGE_INJECTS));

    expect($injects)->toBe([
        ['from' => 'App\\Filter', 'to' => 'App\\MeterRegistry', 'via' => null, 'type' => BeanGraph::EDGE_INJECTS],
    ])->and($graph->unresolved)->toBe([]);
});

it('draws what a factory method itself depends on', function () {
    $graph = BeanGraph::build([
        configuration('App\\Config', [
            ['type' => 'App\\Bus', 'method' => 'bus', 'dependencies' => ['App\\Clock']],
        ]),
        bean('App\\Clock'),
    ]);

    expect($graph->edges)->toContain(
        ['from' => 'App\\Bus', 'to' => 'App\\Clock', 'via' => null, 'type' => BeanGraph::EDGE_INJECTS],
    );
});

// Two factories producing one type is the shape the container now requires #[Primary]/#[Qualifier] to
// disambiguate. Collapsing them onto the type would hide exactly the ambiguity a reader came to look at.
it('keeps competing producers as separate nodes', function () {
    $graph = BeanGraph::build([
        configuration('App\\Config', [
            ['type' => 'App\\Cache', 'method' => 'memory', 'dependencies' => []],
            ['type' => 'App\\Cache', 'method' => 'redis', 'dependencies' => []],
        ]),
    ]);

    $ids = array_column($graph->nodes, 'id');

    expect($ids)->toContain('App\\Config::memory()')
        ->and($ids)->toContain('App\\Config::redis()')
        ->and($graph->kindCounts()[BeanGraph::KIND_BEAN])->toBe(2);
});

// A #[ConfigProperties] DTO is bound and injectable but is neither scanned nor produced, so nothing else
// creates a node for it — it showed up as an unresolved dependency instead of the bean it is.
it('makes a #[ConfigProperties] DTO a node so its consumers link to it', function () {
    $graph = BeanGraph::build(
        [bean('App\\GreetingService', ['App\\GreetingProperties'])],
        ['App\\GreetingProperties' => ['class' => 'App\\GreetingProperties', 'prefix' => 'greeting']],
    );

    expect($graph->kindCounts()[BeanGraph::KIND_CONFIG])->toBe(1)
        ->and($graph->unresolved)->toBe([])
        ->and($graph->edges)->toBe([
            ['from' => 'App\\GreetingService', 'to' => 'App\\GreetingProperties', 'via' => null, 'type' => BeanGraph::EDGE_INJECTS],
        ]);
});

it('groups a node under the first two namespace segments', function () {
    expect(BeanGraph::moduleOf('Firefly\\Observability\\Metrics\\Counter'))->toBe('Firefly\\Observability')
        ->and(BeanGraph::moduleOf('App\\Service'))->toBe('App')
        ->and(BeanGraph::moduleOf('Bare'))->toBe('(global)');
});

it('orders modules by how many nodes they hold', function () {
    $graph = BeanGraph::build([
        bean('Big\\Mod\\A'), bean('Big\\Mod\\B'), bean('Big\\Mod\\C'), bean('Small\\Mod\\A'),
    ]);

    expect($graph->modules()[0])->toBe('Big\\Mod');
});

<?php

declare(strict_types=1);

use Firefly\Admin\BeanGraph;
use Firefly\Admin\BeanGraphSettings;
use Firefly\Admin\BeanModules;
use Firefly\Admin\BeanNeighbourhood;

it('finds complete strongly connected components without recursive graph walks', function () {
    $graph = BeanGraph::build([
        ['class' => 'A', 'dependencies' => ['B']],
        ['class' => 'B', 'dependencies' => ['C']],
        ['class' => 'C', 'dependencies' => ['A']],
    ]);
    expect($graph->components())->toBe([['A', 'B', 'C']]);
});

it('handles a five thousand bean dependency chain with iterative levels', function () {
    $rows = [];
    for ($i = 0; $i < 5000; $i++) {
        $rows[] = ['class' => 'Node'.$i, 'dependencies' => $i < 4999 ? ['Node'.($i + 1)] : []];
    }
    $graph = BeanGraph::build($rows);
    expect($graph->nodes)->toHaveCount(5000)
        ->and($graph->components())->toBe([])
        ->and($graph->nodes[4999]['level'])->toBe(4999);
});

it('bounds a dense focus and reports every omitted neighbour', function () {
    $rows = [['class' => 'Focus']];
    for ($i = 0; $i < 2000; $i++) {
        $rows[] = ['class' => 'Node'.$i, 'dependencies' => ['Focus']];
    }
    $graph = BeanGraph::build($rows);
    $focus = BeanNeighbourhood::around($graph, 'Focus', 2, 'both', new BeanGraphSettings);
    $focus ??= throw new RuntimeException('Missing focus');
    expect($focus->positions)->toHaveCount(17)
        ->and($focus->width)->toBe(992)
        ->and($focus->height)->toBe(728)
        ->and($focus->overflow[0])->toMatchArray(['source' => 'Focus', 'direction' => 'in', 'count' => 1984]);
});

it('allocates the next hop round robin between frontier parents', function () {
    $rows = [['class' => 'F', 'dependencies' => ['Hub', 'Sibling']], ['class' => 'Hub', 'dependencies' => ['A', 'B', 'C', 'D']], ['class' => 'Sibling', 'dependencies' => ['Z']]];
    foreach (['A', 'B', 'C', 'D', 'Z'] as $id) {
        $rows[] = ['class' => $id];
    }
    $focus = BeanNeighbourhood::around(BeanGraph::build($rows), 'F', 2, 'out', new BeanGraphSettings(maxRows: 4));
    $focus ??= throw new RuntimeException('Missing focus');
    expect(array_keys($focus->positions))->toContain('Z')->toHaveCount(7);
});

it('aggregates module coupling separately from production', function () {
    $graph = BeanGraph::build([
        ['class' => 'One\\Mod\\A', 'dependencies' => ['Two\\Mod\\B', 'Contract']],
        ['class' => 'Two\\Mod\\B'],
        ['class' => 'Two\\Mod\\C', 'interfaces' => ['Contract']],
        ['class' => 'One\\Mod\\Config', 'produces' => [['type' => 'Two\\Mod\\Product', 'method' => 'product', 'dependencies' => []]]],
    ]);
    expect(BeanModules::fromGraph($graph)->edges[0])->toMatchArray(['weight' => 2, 'beans' => 2, 'via' => 1, 'concrete' => 1])
        ->and(BeanModules::fromGraph($graph, true)->edges[0]['weight'])->toBe(3);
});

it('keeps every competing factory identity independent of catalogue order', function () {
    $rows = [
        ['class' => 'ConfigA', 'produces' => [['type' => 'Product', 'method' => 'make', 'dependencies' => []]]],
        ['class' => 'ConfigB', 'produces' => [['type' => 'Product', 'method' => 'make', 'dependencies' => []]]],
    ];
    $ids = array_column(BeanGraph::build($rows)->nodes, 'id');
    expect($ids)->toContain('ConfigA::make()', 'ConfigB::make()')->not->toContain('Product');
    expect(array_column(BeanGraph::build(array_reverse($rows))->nodes, 'id'))->toEqualCanonicalizing($ids);
});

it('reports self injection as a cyclic component', function () {
    expect(BeanGraph::build([['class' => 'Self', 'dependencies' => ['Self']]])->components())->toBe([['Self']]);
});

it('does not claim backed off configuration DTOs are registered', function () {
    expect(BeanGraph::build([], ['Absent' => ['class' => 'Absent', 'bound' => false]])->nodes)->toBe([]);
});

it('counts overflow against the final drawing after both sides are placed', function () {
    $rows = [['class' => 'F', 'dependencies' => ['E']], ['class' => 'E', 'dependencies' => ['F']], ['class' => 'X']];
    foreach (['A', 'B', 'C', 'D'] as $id) {
        $rows[] = ['class' => $id, 'dependencies' => ['F', 'X']];
    }
    $focus = BeanNeighbourhood::around(BeanGraph::build($rows), 'F', 1, 'both', new BeanGraphSettings(maxRows: 4)) ?? throw new RuntimeException('Missing focus');
    expect($focus->positions)->toHaveKey('E')->and($focus->overflow)->toBe([]);
});

it('keeps shortest root paths stable across catalogue order', function () {
    $rows = [['class' => 'F'], ['class' => 'B', 'dependencies' => ['F']], ['class' => 'A', 'dependencies' => ['F']]];
    $first = BeanNeighbourhood::around(BeanGraph::build($rows), 'F', 2, 'both', new BeanGraphSettings) ?? throw new RuntimeException('Missing focus');
    $second = BeanNeighbourhood::around(BeanGraph::build(array_reverse($rows)), 'F', 2, 'both', new BeanGraphSettings) ?? throw new RuntimeException('Missing focus');
    expect($first->paths)->toBe($second->paths)->toBe([['A', 'F'], ['B', 'F']]);
});

it('reports competing interface implementations without guessing a container winner', function () {
    $graph = BeanGraph::build([['class' => 'Consumer', 'dependencies' => ['Contract']], ['class' => 'A', 'interfaces' => ['Contract']], ['class' => 'B', 'interfaces' => ['Contract']]]);
    expect($graph->edges)->toBe([])->and($graph->unresolved)->toBe(['Contract']);
});

it('collapses foreign beans into module ports for a bounded module neighbourhood', function () {
    $graph = BeanGraph::build([
        ['class' => 'Own\\Mod\\A', 'dependencies' => ['Foreign\\Mod\\A', 'Foreign\\Mod\\B']],
        ['class' => 'Foreign\\Mod\\A'], ['class' => 'Foreign\\Mod\\B'],
    ]);
    $scope = BeanModules::scope($graph, 'Own\\Mod');
    expect(array_column($scope->nodes, 'id'))->toBe(['Own\\Mod\\A', 'module:Foreign\\Mod'])
        ->and($scope->edges)->toHaveCount(1);
});

it('counts separate interface dependencies on one target in module coupling', function () {
    $graph = BeanGraph::build([
        ['class' => 'One\\Mod\\Consumer', 'dependencies' => ['PortA', 'PortB']],
        ['class' => 'Two\\Mod\\Target', 'interfaces' => ['PortA', 'PortB']],
    ]);
    expect(BeanModules::fromGraph($graph)->edges[0])->toMatchArray(['weight' => 2, 'beans' => 1, 'via' => 2, 'concrete' => 0]);
});

it('keeps a component and same-type factory product as distinct stable identities', function () {
    $rows = [['class' => 'Product'], ['class' => 'Config', 'produces' => [['type' => 'Product', 'method' => 'make', 'dependencies' => []]]]];
    $first = BeanGraph::build($rows);
    $second = BeanGraph::build(array_reverse($rows));
    expect(array_column($first->nodes, 'id'))->toContain('Product', 'Config::make()')
        ->and(array_column($second->nodes, 'id'))->toEqualCanonicalizing(array_column($first->nodes, 'id'));
});

it('counts distinct neighbouring beans separately from declaration relations', function () {
    $graph = BeanGraph::build([['class' => 'Consumer', 'dependencies' => ['IA', 'IB']], ['class' => 'Service', 'interfaces' => ['IA', 'IB']]]);
    $nodes = array_column($graph->nodes, null, 'id');
    expect($nodes['Service']['in'])->toBe(1)->and($nodes['Consumer']['out'])->toBe(1)->and($graph->edges)->toHaveCount(2);
});

it('handles empty and isolated graph focus without inventing relations', function () {
    expect(BeanNeighbourhood::around(BeanGraph::build([]), 'Missing', 2, 'both', new BeanGraphSettings))->toBeNull();
    $focus = BeanNeighbourhood::around(BeanGraph::build([['class' => 'Only']]), 'Only', 2, 'both', new BeanGraphSettings) ?? throw new RuntimeException('Missing focus');
    expect($focus->positions)->toHaveCount(1)->and($focus->height)->toBe(38)->and($focus->overflow)->toBe([])->and($focus->paths)->toBe([['Only']]);
});

it('keeps a bound configuration DTO distinct from a same-type factory', function () {
    $graph = BeanGraph::build([['class' => 'Factory', 'produces' => [['type' => 'Settings', 'method' => 'make', 'dependencies' => []]]]], ['Settings' => ['bound' => true]]);
    $nodes = array_column($graph->nodes, null, 'id');
    expect($nodes)->toHaveKeys(['Settings', 'Factory::make()'])
        ->and($nodes['Settings']['kind'])->toBe('config')
        ->and($nodes['Factory::make()']['kind'])->toBe('bean');
});

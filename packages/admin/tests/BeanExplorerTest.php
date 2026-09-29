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

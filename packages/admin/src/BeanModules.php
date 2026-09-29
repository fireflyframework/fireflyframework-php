<?php

declare(strict_types=1);

namespace Firefly\Admin;

final readonly class BeanModules
{
    /**
     * @param  array<string,array{count:int,sigil:string,hue:int}>  $nodes
     * @param  list<array{from:string,to:string,weight:int,beans:int,via:int,concrete:int}>  $edges
     * @param  list<list<string>>  $cycles
     */
    private function __construct(public array $nodes, public array $edges, public array $cycles) {}

    public static function fromGraph(BeanGraph $graph, bool $produces = false): self
    {
        $nodes = $aggregated = $used = $out = [];
        foreach ($graph->modules() as $module) {
            $leaf = Format::shortClass($module);
            $base = substr($leaf, 0, 2);
            $used[$base] = ($used[$base] ?? 0) + 1;
            $nodes[$module] = ['count' => 0, 'sigil' => $base.($used[$base] > 1 ? $used[$base] : ''), 'hue' => (count($nodes) * 137) % 360];
        }
        foreach ($graph->nodes as $node) {
            $module = BeanGraph::moduleOf($node['id']);
            if (isset($nodes[$module])) {
                $nodes[$module]['count']++;
            }
        }
        foreach ($graph->edges as $edge) {
            if (! $produces && $edge['type'] === BeanGraph::EDGE_PRODUCES) {
                continue;
            }
            $from = BeanGraph::moduleOf($edge['from']);
            $to = BeanGraph::moduleOf($edge['to']);
            if ($from === $to) {
                continue;
            }
            $key = $from.'>'.$to;
            $aggregated[$key] ??= ['from' => $from, 'to' => $to, 'weight' => 0, 'targets' => [], 'interfaces' => [], 'concrete' => 0];
            $aggregated[$key]['weight']++;
            $aggregated[$key]['targets'][$edge['to']] = true;
            if ($edge['via'] !== null) {
                $aggregated[$key]['interfaces'][$edge['via']] = true;
            } else {
                $aggregated[$key]['concrete']++;
            }
            $out[$from][] = $to;
        }
        $edges = [];
        foreach ($aggregated as $edge) {
            $edges[] = ['from' => $edge['from'], 'to' => $edge['to'], 'weight' => $edge['weight'], 'beans' => count($edge['targets']), 'via' => count($edge['interfaces']), 'concrete' => $edge['concrete']];
        }

        return new self($nodes, $edges, array_values(array_filter(GraphComponents::of(array_keys($nodes), $out)->groups, static fn (array $group): bool => count($group) > 1)));
    }

    /**
     * @return list<string> */
    public static function exclusive(BeanGraph $graph, string $module): array
    {
        $adjacency = $graph->adjacency()['out'];
        $own = $others = [];
        foreach ($graph->nodes as $node) {
            if (BeanGraph::moduleOf($node['id']) === $module) {
                $own[] = $node['id'];
            } else {
                $others[] = $node['id'];
            }
        }

        return array_values(array_diff(self::reachable($own, $adjacency), self::reachable($others, $adjacency)));
    }

    /**
     * @param list<string> $queue
     * @param array<string,list<string>> $out
     * @return list<string> */
    private static function reachable(array $queue, array $out): array
    {
        $seen = array_fill_keys($queue, true);
        for ($i = 0; isset($queue[$i]); $i++) {
            foreach ($out[$queue[$i]] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return array_keys($seen);
    }
}

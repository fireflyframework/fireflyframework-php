<?php

declare(strict_types=1);

namespace Firefly\Admin;

final readonly class BeanNeighbourhood
{
    /**
     * @param  array<string,array{x:int,y:int,column:int,row:int}>  $positions
     * @param  list<array{source:string,direction:string,count:int,ids:list<string>}>  $overflow
     * @param  list<list<string>>  $paths
     * @param  list<int>  $columns
     */
    private function __construct(
        public array $positions,
        public array $overflow,
        public array $paths,
        public array $columns,
        public int $width,
        public int $height,
    ) {}

    public static function around(BeanGraph $graph, string $focus, int $depth, string $direction, BeanGraphSettings $settings): ?self
    {
        $nodes = array_column($graph->nodes, null, 'id');
        if (! isset($nodes[$focus])) {
            return null;
        }
        $depth = min(4, max(1, $depth));
        $adjacency = $graph->adjacency();
        $columns = range($direction === 'out' ? 0 : -$depth, $direction === 'in' ? 0 : $depth);
        $placed = [$focus => 0];
        $byColumn = [0 => [$focus]];
        $frontiers = ['in' => [$focus], 'out' => [$focus]];
        $overflow = [];
        for ($hop = 1; $hop <= $depth; $hop++) {
            foreach (['in', 'out'] as $side) {
                if ($direction !== 'both' && $direction !== $side) {
                    continue;
                }
                $column = $side === 'in' ? -$hop : $hop;
                $queues = [];
                foreach ($frontiers[$side] as $source) {
                    $neighbors = array_values(array_unique($adjacency[$side][$source] ?? []));
                    usort($neighbors, static fn (string $a, string $b): int => [$nodes[$b]['in'] + $nodes[$b]['out'], $nodes[$a]['label'], $a] <=> [$nodes[$a]['in'] + $nodes[$a]['out'], $nodes[$b]['label'], $b]);
                    $queues[$source] = $neighbors;
                }
                $selected = [];
                $offset = 0;
                do {
                    $hasNext = false;
                    foreach ($queues as $queue) {
                        if (! isset($queue[$offset])) {
                            continue;
                        }
                        $hasNext = true;
                        $id = $queue[$offset];
                        if (! isset($placed[$id]) && count($selected) < $settings->maxRows && count($placed) < $settings->maxNodes) {
                            $placed[$id] = $column;
                            $selected[] = $id;
                        }
                    }
                    $offset++;
                } while ($hasNext);
                // Order by the mean position of adjacent already-placed parents, then exact identity.
                $ranks = array_flip($frontiers[$side]);
                $score = [];
                foreach ($selected as $id) {
                    $parents = $adjacency[$side === 'in' ? 'out' : 'in'][$id] ?? [];
                    $values = [];
                    foreach ($parents as $parent) {
                        if (isset($ranks[$parent])) {
                            $values[] = $ranks[$parent];
                        }
                    }
                    sort($values);
                    $score[$id] = $values === [] ? 0 : $values[intdiv(count($values), 2)];
                }
                usort($selected, static fn (string $a, string $b): int => [$score[$a], $nodes[$a]['label'], $a] <=> [$score[$b], $nodes[$b]['label'], $b]);
                $byColumn[$column] = $frontiers[$side] = $selected;
                foreach ($queues as $source => $queue) {
                    $hidden = array_values(array_filter($queue, static fn (string $id): bool => ! isset($placed[$id])));
                    if ($hidden !== []) {
                        $overflow[] = ['source' => $source, 'direction' => $side, 'count' => count($hidden), 'ids' => $hidden];
                    }
                }
            }
        }
        $finalOverflow = [];
        foreach ($overflow as $entry) {
            $ids = array_values(array_filter($entry['ids'], static fn (string $id): bool => ! isset($placed[$id])));
            if ($ids !== []) {
                $finalOverflow[] = [...$entry, 'ids' => $ids, 'count' => count($ids)];
            }
        }
        $rows = max(1, ...array_map('count', $byColumn));
        $height = $rows * 46 - 8;
        $positions = [];
        foreach ($columns as $index => $column) {
            $members = $byColumn[$column] ?? [];
            $top = intdiv(($rows - count($members)) * 46, 2);
            foreach ($members as $row => $id) {
                $positions[$id] = ['x' => $index * 204, 'y' => $top + $row * 46, 'column' => $column, 'row' => $row];
            }
        }

        return new self($positions, $finalOverflow, self::rootPaths($focus, $adjacency['in'], $settings->maxPaths), $columns, count($columns) * 204 - 28, $height);
    }

    /**
     * @param array<string,list<string>> $in
     * @return list<list<string>> */
    private static function rootPaths(string $focus, array $in, int $limit): array
    {
        foreach ($in as &$parents) {
            sort($parents, SORT_STRING);
        }
        unset($parents);
        $queue = [$focus];
        $toward = [$focus => null];
        $paths = [];
        for ($i = 0; isset($queue[$i]) && count($paths) < $limit; $i++) {
            $id = $queue[$i];
            if (($in[$id] ?? []) === []) {
                $path = [];
                for ($next = $id; $next !== null; $next = $toward[$next]) {
                    $path[] = $next;
                }
                $paths[] = $path;
            }
            foreach ($in[$id] ?? [] as $parent) {
                if (! array_key_exists($parent, $toward)) {
                    $toward[$parent] = $id;
                    $queue[] = $parent;
                }
            }
        }

        return $paths;
    }
}

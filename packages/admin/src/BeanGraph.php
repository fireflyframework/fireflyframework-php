<?php

declare(strict_types=1);

namespace Firefly\Admin;

/**
 * Turns the beans catalogue into a directed graph a browser can draw and a person can reason about.
 *
 * THE HARD PART IS NOT DRAWING, IT IS RESOLVING. A constructor asks for a TYPE, and that type is very often
 * an interface — `EventPublisher`, `HealthIndicator`, `Cache` — while the bean that satisfies it is a
 * concrete class that merely implements it. An edge list built naively from constructor types therefore
 * points at nodes that do not exist, and the graph comes out as a field of disconnected dots. Every
 * dependency here is resolved through an interface index first, so `PostgresEventPublisher` is what
 * `EventPublisher` actually links to, and the edge is marked `via` so the reader can see the indirection
 * rather than being quietly shown something they did not write.
 *
 * Layering is a longest-path assignment over the resolved edges: a node sits one level below the deepest
 * thing that depends on it, so arrows flow consistently downward and the eye can follow a chain. Cycles
 * cannot hang it — the walk carries its own visited set and simply stops, and the offending edge is reported
 * so a genuine circular dependency shows up as a fact about the application rather than a hung page.
 */
final class BeanGraph
{
    /** A graph past this many nodes is a hairball, not a diagram, so the view offers a filter instead. */
    public const MAX_RENDERABLE = 220;

    /**
     * @param  list<array{id: string, label: string, namespace: string, stereotype: string, scope: string, level: int, in: int, out: int}>  $nodes
     * @param  list<array{from: string, to: string, via: string|null}>  $edges
     * @param  list<array{from: string, to: string}>  $cycles
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly array $cycles,
        public readonly array $unresolved,
    ) {}

    /**
     * @param  array<mixed>  $beans  rows as BeansCatalog publishes them
     */
    public static function fromCatalog(array $beans): self
    {
        [$rows, $byInterface] = self::index($beans);

        $edges = [];
        $unresolved = [];
        foreach ($rows as $class => $row) {
            foreach ($row['dependencies'] as $dependency) {
                $target = isset($rows[$dependency]) ? $dependency : ($byInterface[$dependency] ?? null);

                if ($target === null || $target === $class) {
                    // A type nothing in the container provides: a framework contract satisfied by a binding
                    // rather than a bean, or a class the scan never saw. Reported, not silently dropped —
                    // "why is my bean not in the graph" is exactly the question this page has to answer.
                    if ($target === null) {
                        $unresolved[] = $dependency;
                    }

                    continue;
                }

                $edges[] = ['from' => $class, 'to' => $target, 'via' => $target === $dependency ? null : $dependency];
            }
        }

        $edges = self::dedupe($edges);
        [$levels, $cycles] = self::levels(array_keys($rows), $edges);

        $degree = [];
        foreach ($edges as $edge) {
            $degree[$edge['from']]['out'] = ($degree[$edge['from']]['out'] ?? 0) + 1;
            $degree[$edge['to']]['in'] = ($degree[$edge['to']]['in'] ?? 0) + 1;
        }

        $nodes = [];
        foreach ($rows as $class => $row) {
            $nodes[] = [
                'id' => $class,
                'label' => Format::shortClass($class),
                'namespace' => rtrim(Format::namespaceOf($class), '\\'),
                'stereotype' => $row['stereotype'],
                'scope' => $row['scope'],
                'level' => $levels[$class] ?? 0,
                'in' => $degree[$class]['in'] ?? 0,
                'out' => $degree[$class]['out'] ?? 0,
            ];
        }

        usort($nodes, static fn (array $a, array $b): int => [$a['level'], $a['label']] <=> [$b['level'], $b['label']]);

        return new self($nodes, $edges, $cycles, array_values(array_unique($unresolved)));
    }

    public function isRenderable(): bool
    {
        return count($this->nodes) <= self::MAX_RENDERABLE;
    }

    /**
     * @param  array<mixed>  $beans
     * @return array{0: array<string, array{stereotype: string, scope: string, dependencies: list<string>}>, 1: array<string, string>}
     */
    private static function index(array $beans): array
    {
        $rows = [];
        $byInterface = [];

        foreach ($beans as $bean) {
            if (! is_array($bean) || ! is_string($bean['class'] ?? null)) {
                continue;
            }

            $class = $bean['class'];
            $rows[$class] = [
                'stereotype' => is_string($bean['stereotype'] ?? null) ? $bean['stereotype'] : '',
                'scope' => is_string($bean['scope'] ?? null) ? $bean['scope'] : '',
                'dependencies' => array_values(array_filter(
                    is_array($bean['dependencies'] ?? null) ? $bean['dependencies'] : [],
                    static fn (mixed $d): bool => is_string($d) && $d !== '',
                )),
            ];

            foreach (is_array($bean['interfaces'] ?? null) ? $bean['interfaces'] : [] as $interface) {
                // First implementor wins, deterministically: the catalogue is emitted in scan order, so the
                // same application always draws the same graph. An interface with several implementors is a
                // real ambiguity the container resolves with #[Primary]/#[Qualifier], and the graph says so
                // by listing the edge as `via` rather than pretending the choice was obvious.
                if (is_string($interface) && ! isset($byInterface[$interface])) {
                    $byInterface[$interface] = $class;
                }
            }
        }

        return [$rows, $byInterface];
    }

    /**
     * @param  list<array{from: string, to: string, via: string|null}>  $edges
     * @return list<array{from: string, to: string, via: string|null}>
     */
    private static function dedupe(array $edges): array
    {
        $seen = [];
        $out = [];
        foreach ($edges as $edge) {
            $key = $edge['from'].'>'.$edge['to'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $edge;
            }
        }

        return $out;
    }

    /**
     * Longest-path layering, so a node always sits below everything that depends on it and arrows read
     * downward. Depth is memoised and the walk carries a visited set, so a cycle terminates instead of
     * recursing forever — and the edge that closed it is reported.
     *
     * @param  list<string>  $classes
     * @param  list<array{from: string, to: string, via: string|null}>  $edges
     * @return array{0: array<string,int>, 1: list<array{from: string, to: string}>}
     */
    private static function levels(array $classes, array $edges): array
    {
        $out = [];
        foreach ($edges as $edge) {
            $out[$edge['from']][] = $edge['to'];
        }

        $depth = [];
        $cycles = [];

        $walk = static function (string $node, array $path) use (&$walk, &$depth, &$cycles, $out): int {
            if (isset($depth[$node])) {
                return $depth[$node];
            }
            if (isset($path[$node])) {
                return 0; // the caller records the closing edge
            }

            $path[$node] = true;
            $deepest = 0;
            foreach ($out[$node] ?? [] as $next) {
                if (isset($path[$next])) {
                    $cycles[] = ['from' => $node, 'to' => $next];

                    continue;
                }
                $deepest = max($deepest, $walk($next, $path) + 1);
            }

            return $depth[$node] = $deepest;
        };

        foreach ($classes as $class) {
            $walk($class, []);
        }

        // Depth counts how far a node's longest chain of dependencies runs; the drawing wants the opposite,
        // with dependents on top. Flip it so level 0 is the thing nothing depends on.
        $max = $depth === [] ? 0 : max($depth);
        $levels = [];
        foreach ($depth as $class => $value) {
            $levels[$class] = $max - $value;
        }

        return [$levels, self::dedupeCycles($cycles)];
    }

    /**
     * @param  list<array{from: string, to: string}>  $cycles
     * @return list<array{from: string, to: string}>
     */
    private static function dedupeCycles(array $cycles): array
    {
        $seen = [];
        $out = [];
        foreach ($cycles as $cycle) {
            $key = $cycle['from'].'>'.$cycle['to'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $cycle;
            }
        }

        return $out;
    }
}

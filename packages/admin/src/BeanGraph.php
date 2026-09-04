<?php

declare(strict_types=1);

namespace Firefly\Admin;

/**
 * The application's wiring as a directed graph a browser can draw and a person can reason about.
 *
 * WHAT COUNTS AS A NODE, AND WHY THE FIRST VERSION WAS NEARLY EMPTY. A LaraFly application has three kinds
 * of bean, and the first version of this class only knew about one:
 *
 *   component  a scanned #[Component]/#[Service]/#[Repository]/#[RestController] class
 *   bean       a value produced by a #[Bean] factory method on a #[Configuration]
 *   config     a #[ConfigProperties] DTO bound from configuration
 *
 * Only components were nodes. But a FRAMEWORK's wiring lives almost entirely in the second kind — an
 * auto-configuration is a #[Configuration] whose #[Bean] methods produce MeterRegistry, TransactionTemplate,
 * AggregateTracker and so on — so every edge pointing at one of those pointed at a node that did not exist.
 * Measured on a stock skeleton: 42 nodes, 41 #[Bean] products missing, 21 dangling dependencies, and exactly
 * ONE edge drawn. The graph was not "sparse", it was structurally incapable of showing framework wiring.
 *
 * THE SECOND REASON EDGES VANISH IS INTERFACES. A constructor asks for a TYPE, and that type is usually an
 * interface — EventPublisher, HealthIndicator, Cache — while the bean satisfying it is a concrete class or a
 * factory return. Every dependency is therefore resolved through an interface index, and the edge records
 * the interface in `via` so the reader sees the indirection rather than being quietly shown something they
 * did not write.
 *
 * Layering is a longest-path assignment over the resolved edges, so a node sits below everything that
 * depends on it and arrows read downward. The walk carries its own visited set, so a cycle terminates and
 * the edge that closed it is REPORTED — which matters, because the container has no cycle detection and a
 * cycle among eager singletons exhausts memory at boot.
 */
final class BeanGraph
{
    public const KIND_COMPONENT = 'component';

    public const KIND_BEAN = 'bean';

    public const KIND_CONFIG = 'config';

    /** A dependency the consumer declared and the container satisfies. */
    public const EDGE_INJECTS = 'injects';

    /** A #[Configuration] to the value one of its #[Bean] methods produces. */
    public const EDGE_PRODUCES = 'produces';

    /**
     * @param  list<array{id: string, label: string, namespace: string, kind: string, stereotype: string, scope: string, detail: string, level: int, in: int, out: int}>  $nodes
     * @param  list<array{from: string, to: string, via: string|null, type: string}>  $edges
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
     * @param  array<mixed>  $configProperties  rows as the configprops endpoint publishes them, keyed by class
     */
    public static function build(array $beans, array $configProperties = []): self
    {
        $index = new BeanGraphIndex;

        foreach ($beans as $row) {
            if (! is_array($row) || ! is_string($row['class'] ?? null)) {
                continue;
            }
            $index->addComponent($row);
        }

        foreach ($configProperties as $class => $row) {
            if (is_array($row) && is_string($row['class'] ?? $class)) {
                $index->addConfigProperties(is_string($row['class'] ?? null) ? $row['class'] : (string) $class);
            }
        }

        [$edges, $unresolved] = $index->edges();
        [$levels, $cycles] = self::levels($index->ids(), $edges);

        $degree = [];
        foreach ($edges as $edge) {
            $degree[$edge['from']]['out'] = ($degree[$edge['from']]['out'] ?? 0) + 1;
            $degree[$edge['to']]['in'] = ($degree[$edge['to']]['in'] ?? 0) + 1;
        }

        $nodes = [];
        foreach ($index->nodes() as $id => $node) {
            $nodes[] = [
                ...$node,
                'level' => $levels[$id] ?? 0,
                'in' => $degree[$id]['in'] ?? 0,
                'out' => $degree[$id]['out'] ?? 0,
            ];
        }

        usort($nodes, static fn (array $a, array $b): int => [$a['level'], $a['label']] <=> [$b['level'], $b['label']]);

        return new self($nodes, $edges, $cycles, $unresolved);
    }

    /**
     * Kept for the older two-argument shape.
     *
     * @param  array<mixed>  $beans
     */
    public static function fromCatalog(array $beans): self
    {
        return self::build($beans);
    }

    /** @return array<string,int> node count per kind, for the page's summary */
    public function kindCounts(): array
    {
        $counts = [self::KIND_COMPONENT => 0, self::KIND_BEAN => 0, self::KIND_CONFIG => 0];
        foreach ($this->nodes as $node) {
            $counts[$node['kind']] = ($counts[$node['kind']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The namespace roots present, most-populated first — the drawing colours by module, and a legend has to
     * name them.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        $counts = [];
        foreach ($this->nodes as $node) {
            $module = self::moduleOf($node['id']);
            $counts[$module] = ($counts[$module] ?? 0) + 1;
        }

        arsort($counts);

        return array_keys($counts);
    }

    /** The first two namespace segments — `Firefly\Observability`, `App\Http` — which is how a reader groups. */
    public static function moduleOf(string $id): string
    {
        $parts = explode('\\', ltrim($id, '\\'));

        return match (true) {
            count($parts) <= 1 => '(global)',
            count($parts) === 2 => $parts[0],
            default => $parts[0].'\\'.$parts[1],
        };
    }

    /**
     * Longest-path layering, so a node always sits below everything that depends on it. Depth is memoised and
     * the walk carries a visited set, so a cycle terminates instead of recursing forever — and the edge that
     * closed it is reported.
     *
     * @param  list<string>  $ids
     * @param  list<array{from: string, to: string, via: string|null, type: string}>  $edges
     * @return array{0: array<string,int>, 1: list<array{from: string, to: string}>}
     */
    private static function levels(array $ids, array $edges): array
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
                return 0;
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

        foreach ($ids as $id) {
            $walk($id, []);
        }

        // Depth counts how far a node's longest chain of dependencies runs; the drawing wants the opposite,
        // with dependents on top. Flip it so level 0 is what nothing depends on.
        $max = $depth === [] ? 0 : max($depth);
        $levels = [];
        foreach ($depth as $id => $value) {
            $levels[$id] = $max - $value;
        }

        $seen = [];
        $unique = [];
        foreach ($cycles as $cycle) {
            $key = $cycle['from'].'>'.$cycle['to'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $cycle;
            }
        }

        return [$levels, $unique];
    }
}

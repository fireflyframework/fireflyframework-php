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
 * Iterative Tarjan analysis condenses strongly connected components before assigning longest-path levels.
 * Cycle membership is a wiring fact; production edges mean it need not be a runtime constructor cycle.
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
        $index->countProducers($beans, $configProperties);

        foreach ($beans as $row) {
            if (! is_array($row) || ! is_string($row['class'] ?? null)) {
                continue;
            }
            $index->addComponent($row);
        }

        foreach ($configProperties as $class => $row) {
            if (is_array($row) && ($row['bound'] ?? true) !== false && is_string($row['class'] ?? $class)) {
                $index->addConfigProperties(is_string($row['class'] ?? null) ? $row['class'] : (string) $class);
            }
        }

        [$edges, $unresolved] = $index->edges();
        [$levels, $cycles] = self::levels($index->ids(), $edges);

        $degree = [];
        foreach ($edges as $edge) {
            $degree[$edge['from']]['out'][$edge['to']] = true;
            $degree[$edge['to']]['in'][$edge['from']] = true;
        }

        $nodes = [];
        foreach ($index->nodes() as $id => $node) {
            $nodes[] = [
                ...$node,
                'level' => $levels[$id] ?? 0,
                'in' => count($degree[$id]['in'] ?? []),
                'out' => count($degree[$id]['out'] ?? []),
            ];
        }

        usort($nodes, static fn (array $a, array $b): int => [$a['level'], $a['label']] <=> [$b['level'], $b['label']]);

        return new self($nodes, $edges, $cycles, $unresolved);
    }

    /**
     * Kept for the older two-argument shape.
     *
     *
     * @param  array<mixed>  $beans
     */
    public static function fromCatalog(array $beans): self
    {
        return self::build($beans);
    }

    /**
     * @return array<string,int> node count per kind, for the page's summary */
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
     * Longest-path levels on the component DAG, with every internal cyclic edge retained for compatibility.
     *
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
        $components = GraphComponents::of($ids, $out);
        $depth = [];
        $cycles = [];
        // Tarjan emits dependency components before their consumers.
        foreach ($components->groups as $index => $members) {
            $depth[$index] = 0;
            foreach ($members as $member) {
                foreach ($out[$member] ?? [] as $target) {
                    $other = $components->membership[$target];
                    if ($other !== $index) {
                        $depth[$index] = max($depth[$index], $depth[$other] + 1);
                    } else {
                        $cycles[] = ['from' => $member, 'to' => $target];
                    }
                }
            }
        }
        $max = $depth === [] ? 0 : max($depth);
        $levels = [];
        foreach ($components->membership as $id => $group) {
            $levels[$id] = $max - $depth[$group];
        }

        return [$levels, $cycles];
    }

    /**
     * @return array{out: array<string,list<string>>, in: array<string,list<string>>} */
    public function adjacency(): array
    {
        $out = $in = [];
        foreach ($this->edges as $edge) {
            $out[$edge['from']][] = $edge['to'];
            $in[$edge['to']][] = $edge['from'];
        }

        return ['out' => $out, 'in' => $in];
    }

    /** Complete cyclic components, rather than arbitrary closing pairs.
     * @return list<list<string>> */
    public function components(): array
    {
        $self = [];
        foreach ($this->edges as $edge) {
            if ($edge['from'] === $edge['to']) {
                $self[$edge['from']] = true;
            }
        }

        return array_values(array_filter(
            GraphComponents::of(array_column($this->nodes, 'id'), $this->adjacency()['out'])->groups,
            static fn (array $group): bool => count($group) > 1 || isset($self[$group[0]]),
        ));
    }
}

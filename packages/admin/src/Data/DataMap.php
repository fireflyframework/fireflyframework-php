<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * The application's entities and the joins between them, laid out for drawing.
 *
 * WHY IT IS NOT THE BEAN GRAPH. That graph answers "what is wired to what" over hundreds of container
 * definitions, and its layout problem is density. This one answers "what points at what" over a handful of
 * TABLES, where every node is a box that has to show its own columns and every edge is a foreign key with a
 * name. A schema diagram that hid the column an edge joins on would be a picture of the shape of the model
 * with the one fact you opened it for left out.
 *
 * LAYERS COME FROM WHO POINTS AT WHOM. An entity nothing references sits at the top and each reference
 * pushes its target down one, so arrows read downward and the aggregate roots — the tables you actually
 * start a query from — end up on the first row. A cycle (two entities that reference each other, which is
 * legal and common with a nullable key) terminates on a visited set rather than recursing, and the edge that
 * closed it is still drawn.
 *
 * IT SHOWS EVERY BROWSABLE RESOURCE, including the ones with no relations at all. A standalone table is a
 * fact about the model worth seeing, and a diagram that silently dropped it would let a reader conclude the
 * application has fewer tables than it does.
 */
final class DataMap
{
    /**
     * @param  list<array{slug: string, label: string, entity: string, table: string, columns: list<array{name: string, type: string, identifier: bool}>, more: int, level: int}>  $nodes
     * @param  list<array{from: string, to: string, column: string, target: string, kind: string, toMany: bool}>  $edges
     * @param  list<array{from: string, to: string}>  $cycles
     */
    private function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly array $cycles,
    ) {}

    /** How many columns a node lists before it says "and N more". */
    private const int COLUMN_LIMIT = 8;

    public static function build(DataBrowser $browser): self
    {
        $resources = $browser->resources();

        $nodes = [];
        foreach ($resources as $resource) {
            $schema = $browser->schema($resource->slug);
            $columns = [];

            foreach ($schema === null ? [] : $schema->columns as $column) {
                $columns[] = ['name' => $column->name, 'type' => $column->type, 'identifier' => $column->identifier];
            }

            $nodes[$resource->slug] = [
                'slug' => $resource->slug,
                'label' => $resource->label,
                'entity' => $resource->entityClass ?? '',
                'table' => $resource->table ?? '',
                'columns' => array_slice($columns, 0, self::COLUMN_LIMIT),
                'more' => max(0, count($columns) - self::COLUMN_LIMIT),
                'level' => 0,
            ];
        }

        $edges = [];
        foreach ($resources as $resource) {
            foreach ($browser->relationsFor($resource->slug) as $relation) {
                // Only an edge the browser can actually follow is drawn. A pivot or a polymorphic join has no
                // single column, and a line with no join to name would be decoration.
                $related = $relation->relatedSlug;
                if (! $relation->navigable() || $related === null || ! isset($nodes[$related])) {
                    continue;
                }

                // A hasMany and the belongsTo facing it are ONE foreign key seen from two ends. Drawing both
                // would double every line in the diagram, so each is normalised to point from the table that
                // HOLDS the key to the table it references — which is also the direction the arrow means.
                [$from, $to, $column, $target] = $relation->toMany
                    ? [$related, $resource->slug, $relation->column, $relation->target]
                    : [$resource->slug, $related, $relation->column, $relation->target];

                $edges[$from.'>'.$to.'>'.$column] = [
                    'from' => $from,
                    'to' => $to,
                    'column' => $column,
                    'target' => $target,
                    'kind' => $relation->kind,
                    'toMany' => $relation->toMany,
                ];
            }
        }

        /** @var list<array{from: string, to: string, column: string, target: string, kind: string, toMany: bool}> $edges */
        $edges = array_values($edges);

        /** @var list<string> $ids */
        $ids = array_keys($nodes);
        [$levels, $cycles] = self::levels($ids, $edges);

        foreach ($nodes as $slug => $node) {
            $nodes[$slug]['level'] = $levels[$slug] ?? 0;
        }

        $ordered = array_values($nodes);
        usort($ordered, static fn (array $a, array $b): int => [$a['level'], $a['label']] <=> [$b['level'], $b['label']]);

        return new self($ordered, $edges, $cycles);
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** @return list<int> the distinct levels, in drawing order */
    public function levelsPresent(): array
    {
        $levels = array_values(array_unique(array_map(static fn (array $n): int => $n['level'], $this->nodes)));
        sort($levels);

        return $levels;
    }

    /**
     * Longest-path layering over "references", so a table sits below everything that points at it.
     *
     * @param  list<string>  $ids
     * @param  list<array{from: string, to: string, column: string, target: string, kind: string, toMany: bool}>  $edges
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

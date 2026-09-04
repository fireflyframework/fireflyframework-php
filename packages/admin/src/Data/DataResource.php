<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * One browsable resource: a repository bean, the entity it manages, and what it can actually do.
 *
 * The capability flags are not decoration — every read and write path branches on them, and the view needs
 * them to decide which controls to draw. `$paged` says the repository implements PagingAndSortingRepository,
 * so a page can be asked for by page number instead of materialising the table; `$eloquent` says it is an
 * EloquentRepository whose `$model` resolved to a real Eloquent model class, which is what makes column
 * derivation from the live schema, SQL-side search and mutation possible at all. A resource with neither is
 * still listable — see DataQueryEngine's fallback — it is just expensive and read-only.
 *
 * `$slug` is what appears in a URL and `$label` is what appears in a menu; both are derived once by
 * DataResourceRegistry, which owns the collision rules.
 */
final readonly class DataResource
{
    /**
     * @param  class-string  $repositoryClass
     * @param  class-string|null  $entityClass
     */
    public function __construct(
        public string $slug,
        public string $label,
        public string $repositoryClass,
        public ?string $entityClass = null,
        public ?string $table = null,
        public bool $paged = false,
        public bool $eloquent = false,
    ) {}

    /**
     * Whether this resource has a live Eloquent model behind it — the precondition for schema-derived
     * columns, SQL-side search/sort, and any write at all.
     */
    public function isEloquentBacked(): bool
    {
        return $this->eloquent && $this->entityClass !== null;
    }

    /** The short class name of whatever the resource is "of", for headings and breadcrumbs. */
    public function shortName(): string
    {
        $class = $this->entityClass ?? $this->repositoryClass;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}

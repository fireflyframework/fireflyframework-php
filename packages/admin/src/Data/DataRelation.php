<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * One edge between two entities, as the browser can actually follow it.
 *
 * `$relatedSlug` is the point of the whole class. A relation whose other end is not a browsable resource —
 * no repository declares it, or its resource is excluded — is still worth SHOWING (it tells a reader the
 * shape of the model) but must not be rendered as a link, because there is nowhere for that link to go. The
 * two cases are distinguished here rather than in the view, so a template cannot accidentally mint a URL
 * that 404s.
 *
 * `$column` is the column on the SIDE BEING VIEWED that carries the link, and `$target` the column it points
 * at. For a to-one that means a foreign key on this row pointing at the other table's key; for a to-many it
 * is the other way round, which is exactly what lets a record page turn "this order" into "the lines whose
 * order_id is 7" with one filter.
 */
final readonly class DataRelation
{
    public function __construct(
        public string $name,
        public string $label,
        public string $kind,
        public string $relatedClass,
        public ?string $relatedSlug,
        public string $column,
        public string $target,
        public bool $toMany,
    ) {}

    /** Whether the other end can actually be opened in the browser. */
    public function navigable(): bool
    {
        return $this->relatedSlug !== null && $this->column !== '' && $this->target !== '';
    }

    public function shortRelated(): string
    {
        return str_contains($this->relatedClass, '\\')
            ? substr($this->relatedClass, strrpos($this->relatedClass, '\\') + 1)
            : $this->relatedClass;
    }
}

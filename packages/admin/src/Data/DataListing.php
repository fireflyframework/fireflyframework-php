<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * One page of a resource: the rows, the grand total, the query that produced them, and — when it did not
 * work — a reason safe to put on a page.
 *
 * WHY A FAILED LISTING IS A LISTING AND NOT AN EXCEPTION. The three ways this can go wrong (the browser is
 * switched off, the resource does not exist, the query threw) all have to render as a page, and a view that
 * has to wrap every call in try/catch to stay alive will eventually forget one. So there is exactly one
 * return type: `$error === null` means the rows are real, and otherwise `$error` is a sentence the view can
 * print and `$rows` is empty. `$resource` and `$schema` are nullable for the same reason — an unknown slug
 * has neither, and the caller still needs something to render.
 *
 * WHY `$error` NEVER CONTAINS THE EXCEPTION MESSAGE. Laravel's QueryException stringifies the failing SQL
 * *and its bindings* into `getMessage()`. Echoing that to the browser would publish the schema and, far
 * worse, the values that were bound — which on a search over a users table is the operator's own query and on
 * a detail lookup is a primary key. DataBrowser builds this field from a fixed sentence plus, at most, the
 * exception's class name; the message stays in the exception, where a log can have it.
 */
final readonly class DataListing
{
    /**
     * @param  list<array<string, mixed>>  $rows  each row keyed by column name, in schema column order
     */
    public function __construct(
        public ?DataResource $resource,
        public ?DataSchema $schema,
        public array $rows,
        public int $total,
        public int $page = 1,
        public int $perPage = 25,
        public ?string $sort = null,
        public string $direction = 'asc',
        public ?string $search = null,
        public ?string $error = null,
    ) {}

    /**
     * The empty-with-a-reason constructor every refusal and every caught failure goes through.
     */
    public static function failure(
        string $error,
        ?DataResource $resource = null,
        ?DataSchema $schema = null,
        int $page = 1,
        int $perPage = 25,
    ): self {
        return new self($resource, $schema, [], 0, $page, $perPage, null, 'asc', null, $error);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function totalPages(): int
    {
        return $this->perPage > 0 ? max(1, (int) ceil($this->total / $this->perPage)) : 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    /**
     * The columns the view should draw, in order — empty when the schema could not be derived, which the
     * view must render as "no columns" rather than as "no rows".
     *
     * @return list<DataColumn>
     */
    public function columns(): array
    {
        return $this->schema === null ? [] : $this->schema->columns;
    }
}

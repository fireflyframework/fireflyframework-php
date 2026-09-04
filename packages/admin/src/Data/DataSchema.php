<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * The ordered column list of one resource, plus the identifier column name and where the list came from.
 *
 * WHY THE IDENTIFIER IS A FIRST-CLASS FIELD AND NOT "the column called id". Everything past the listing keys
 * on it: the detail view addresses a row by it, delete addresses a row by it, and update addresses a row by
 * it while refusing to write it. A browser that guessed wrong would render a link to a row it cannot fetch,
 * or — far worse — issue a delete whose WHERE clause matched more than one row. So it is derived explicitly
 * (Eloquent's own `getKeyName()`, or a named property on a plain entity) and it is allowed to be NULL: a
 * resource whose identifier could not be determined is browsable as a LIST and nothing else, which is an
 * honest degradation. DataBrowser refuses find/delete/update on it with a reason rather than improvising.
 *
 * `$source` records the derivation so the operator can tell a schema-backed column list (authoritative, every
 * column, real nullability) from an entity-backed one (whatever the class chose to expose) from a failed
 * derivation. "Why is this column missing" is a question the page has to be able to answer.
 */
final readonly class DataSchema
{
    /** Columns read from the live database via the schema builder — authoritative. */
    public const string SOURCE_SCHEMA = 'schema';

    /** Columns read from the entity class's public/promoted properties — whatever the class exposes. */
    public const string SOURCE_ENTITY = 'entity';

    /** Nothing could be derived: no connection, no model, no typed entity. */
    public const string SOURCE_NONE = 'none';

    /**
     * @param  list<DataColumn>  $columns
     */
    public function __construct(
        public array $columns,
        public ?string $identifier = null,
        public string $source = self::SOURCE_NONE,
    ) {}

    public static function empty(): self
    {
        return new self([], null, self::SOURCE_NONE);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    public function has(string $name): bool
    {
        return $this->column($name) !== null;
    }

    public function column(string $name): ?DataColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (DataColumn $column): string => $column->name, $this->columns);
    }

    /**
     * The columns a free-text search may look in: string-typed and not a secret.
     *
     * Secrets are excluded from search for the same reason they are masked — a search box that answers "yes,
     * some row's api_token starts with sk_live_9" is an oracle, and an operator can walk one character at a
     * time. Non-string columns are excluded because a LIKE over an integer or a timestamp is a per-driver
     * coercion (sqlite says yes, Postgres says no) and a search box that explodes on one backend and works on
     * another is worse than one that only searches text.
     *
     * @return list<string>
     */
    public function searchable(): array
    {
        return array_values(array_map(
            static fn (DataColumn $column): string => $column->name,
            array_filter(
                $this->columns,
                static fn (DataColumn $column): bool => $column->type === DataColumn::TYPE_STRING && ! $column->sensitive,
            ),
        ));
    }

    /**
     * The columns a FILTER may name.
     *
     * SENSITIVE COLUMNS ARE EXCLUDED, for exactly the reason they are excluded from search — and this had to
     * be learned twice. A masked column renders as `******`, but a filter over it answers a yes/no question
     * about its real value, and a yes/no question you can ask repeatedly is an extraction oracle: `starts
     * with 'a'`, `starts with 'b'`, … recovers the whole secret one character at a time while the page never
     * displays it. Proven against the fixture: the listing showed `******` and twenty-one filtered queries
     * returned `correct horse battery`.
     *
     * Unlike `searchable()` this is not restricted to strings — filtering an `int` or a `datetime` is the
     * ordinary case, and the comparison set includes `>` and `<` precisely for them.
     *
     * @return list<string>
     */
    public function filterable(): array
    {
        return array_values(array_map(
            static fn (DataColumn $column): string => $column->name,
            array_filter($this->columns, static fn (DataColumn $column): bool => ! $column->sensitive),
        ));
    }

    /**
     * The columns an ORDER BY may name. JSON is excluded because ordering a serialized blob sorts its text,
     * which looks like it worked and means nothing.
     *
     * @return list<string>
     */
    public function sortable(): array
    {
        return array_values(array_map(
            static fn (DataColumn $column): string => $column->name,
            array_filter($this->columns, static fn (DataColumn $column): bool => $column->type !== DataColumn::TYPE_JSON),
        ));
    }

    /** The identifier column itself, when one was derived AND is present in the column list. */
    public function identifierColumn(): ?DataColumn
    {
        return $this->identifier === null ? null : $this->column($this->identifier);
    }
}

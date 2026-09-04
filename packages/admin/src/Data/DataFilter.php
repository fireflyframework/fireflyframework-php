<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * A single equality constraint on a listing — "the lines whose order_id is 7".
 *
 * IT IS NOT A GENERAL QUERY LANGUAGE, on purpose. The only filter the browser accepts is one column equal to
 * one value, because the only filter it needs to OFFER is the one a relation implies: every "show me the
 * children of this row" link is exactly that shape. A richer filter builder in a URL an operator can edit is
 * a query surface, and a query surface over arbitrary columns is a very different security review from a
 * paginated read.
 *
 * The column is validated against the schema by the caller before it reaches a query — a filter naming a
 * column the resource does not have is dropped rather than passed to the database, so a hand-edited URL
 * cannot probe for column names.
 */
final readonly class DataFilter
{
    public function __construct(
        public string $column,
        public string $value,
    ) {}

    /** The query-string form, so a listing link and a "clear" link are built from one place. */
    public function toQuery(): string
    {
        return 'fk='.urlencode($this->column).'&fv='.urlencode($this->value);
    }
}

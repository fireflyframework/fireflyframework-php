<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * One constraint on a listing: a column, a comparison, and a value.
 *
 * IT IS A FIXED SET OF COMPARISONS OVER A VALIDATED COLUMN, not a query language. The column is checked
 * against the resource's schema before anything reaches a driver — an unknown one is dropped rather than
 * passed through, so a hand-edited URL cannot probe for column names — and the value is always a bound
 * parameter. What an operator can express is therefore exactly what the eight operators below allow, over
 * exactly the columns the resource already publishes, which is the same surface the search box has had all
 * along, made precise.
 *
 * THE SHORT FORM EXISTS FOR RELATIONS. `fk`/`fv` in a URL is one equality and is what every "children of
 * this row" link produces; the indexed form (`fc[]`/`fo[]`/`fv[]`) is what the filter bar builds. Keeping
 * both means a relation link stays short and readable while the UI is not limited to a single condition.
 */
final readonly class DataFilter
{
    public const string EQ = 'eq';

    public const string NE = 'ne';

    public const string CONTAINS = 'contains';

    public const string STARTS = 'starts';

    public const string GT = 'gt';

    public const string LT = 'lt';

    public const string NULL = 'null';

    public const string NOT_NULL = 'notnull';

    public function __construct(
        public string $column,
        public string $operator = self::EQ,
        public string $value = '',
    ) {}

    /**
     * The operators, id => the label a person picks from.
     *
     * @return array<string, string>
     */
    public static function operators(): array
    {
        return [
            self::EQ => 'is',
            self::NE => 'is not',
            self::CONTAINS => 'contains',
            self::STARTS => 'starts with',
            self::GT => 'greater than',
            self::LT => 'less than',
            self::NULL => 'is empty',
            self::NOT_NULL => 'is not empty',
        ];
    }

    public static function isOperator(string $operator): bool
    {
        return array_key_exists($operator, self::operators());
    }

    /** Whether this comparison uses the value at all — `is empty` does not. */
    public function needsValue(): bool
    {
        return $this->operator !== self::NULL && $this->operator !== self::NOT_NULL;
    }

    public function label(): string
    {
        return self::operators()[$this->operator] ?? $this->operator;
    }

    /**
     * This filter set as query PARAMETERS, which is the form every link builder wants.
     *
     * A LINK CARRIES A FILTER AS DATA, NOT AS A STRING IT WAS HANDED. `ListingQuery` is given this array as
     * the parameters it must not lose, and it writes them out with `http_build_query` alongside its own —
     * which is what lets a sort link, a page link and a search form each rebuild the whole URL from parsed
     * values rather than concatenating someone else's fragment onto their own.
     *
     * @param  list<self>  $filters
     * @return array<string, string|list<string>>
     */
    public static function toParameters(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        // TWO SPELLINGS, ONE MEANING. `fk`/`fv` is a single equality and is what every relation link
        // produces — short enough to read in a status bar. Both are validated identically downstream.
        if (count($filters) === 1 && $filters[0]->operator === self::EQ) {
            return ['fk' => $filters[0]->column, 'fv' => $filters[0]->value];
        }

        return [
            'fc' => array_map(static fn (self $filter): string => $filter->column, $filters),
            'fo' => array_map(static fn (self $filter): string => $filter->operator, $filters),
            'fv' => array_map(static fn (self $filter): string => $filter->value, $filters),
        ];
    }

    /**
     * The query-string form, for the one caller that still wants a string.
     *
     * Built by http_build_query rather than by concatenating urlencode() calls: the hand-built version was
     * correct and was one edit away from not being, which is exactly the class of bug this wave is removing.
     *
     * @param  list<self>  $filters
     */
    public static function toQuery(array $filters): string
    {
        return http_build_query(self::toParameters($filters));
    }

    /** A one-line description of what this filter narrows to, for the banner above a filtered listing. */
    public function describe(): string
    {
        return $this->needsValue()
            ? $this->column.' '.$this->label().' '.$this->value
            : $this->column.' '.$this->label();
    }
}

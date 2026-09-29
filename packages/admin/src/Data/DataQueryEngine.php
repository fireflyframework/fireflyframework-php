<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use BackedEnum;
use DateTimeInterface;
use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Firefly\Admin\RowComparator;
use Firefly\Data\Repository\CrudRepository;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\PagingAndSortingRepository;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Repository\Specification\Specification;
use Firefly\Data\Repository\Specification\Specifications;
use Firefly\Kernel\Exception\Infrastructure\CannotAcquireLockException;
use Firefly\Kernel\Exception\Infrastructure\DataAccessResourceFailureException;
use Firefly\Kernel\Exception\Infrastructure\DataIntegrityViolationException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Firefly\Kernel\Exception\Infrastructure\QueryTimeoutException;
use Firefly\Kernel\Exception\Infrastructure\TransactionTimedOutException;
use Firefly\Kernel\Exception\Infrastructure\TransientDataAccessResourceException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use Throwable;

/**
 * The READ half of the browser: turn (resource, page, sort, search) into rows, and (resource, id) into one
 * record. Every value that leaves here has been through the masker and the normaliser.
 *
 * FOUR PATHS, AND WHY EACH EXISTS.
 *
 *  1. PAGED, UNSEARCHED — `findPaged(Pageable)`. The repository does the offset, the limit, the ORDER BY and
 *     the COUNT, so the database returns one page and the total. This is the path every well-declared
 *     repository takes and the only one whose cost is independent of table size.
 *
 *  2. PAGED, SEARCHED, ELOQUENT — `findBySpecificationPaged(Specification, Pageable)`. `findPaged` cannot
 *     carry a predicate, and the naive fix (fetch everything, filter in PHP) is worst exactly where search
 *     matters, on the big table. EloquentRepository already exposes a public specification seam that applies
 *     the predicate to the repository's OWN `query()` builder, so the filter, the page and the count all
 *     happen in SQL and any constraint a repository added by overriding `query()` still applies. Going around
 *     it with `Model::query()` would have been shorter and would have silently dropped that constraint —
 *     which on a repository that scopes to a tenant is a cross-tenant disclosure.
 *
 *  3. UNPAGED — `findAll()`, then sort and slice IN PHP. THIS IS A FOOT-GUN AND IT IS LOAD-BEARING TO SAY SO:
 *     `findAll()` on a plain CrudRepository issues `SELECT *` with no LIMIT, hydrates every row of the table
 *     into PHP objects, and only then does the browser throw away all but 25 of them. On a table of ten
 *     thousand rows that is a slow page; on a table of ten million it is an out-of-memory that kills the
 *     worker, and it will happen on the FIRST click, not gradually. There is no way to do better through the
 *     CrudRepository interface — it has no limit, no offset and no count-with-predicate — so the honest
 *     options were "refuse to browse repositories that cannot page" or "browse them and say what it costs".
 *     This is the second. A repository that will be browsed against a large table should implement
 *     PagingAndSortingRepository, at which point it takes path 1.
 *
 *  4. UNPAGED, SEARCHED — path 3 with an additional in-PHP substring filter. No SQL is involved in the
 *     matching at all, so the term cannot reach a query planner, let alone a parser.
 *
 * SEARCH IS BOUND, NEVER INTERPOLATED. On the SQL paths the term is passed as a BINDING to
 * `where(column, 'like', ?)` — it is never concatenated into a fragment, never handed to `whereRaw`, and
 * therefore cannot become SQL no matter what it contains. The COLUMN names are not caller data at all: they
 * come from DataSchema, which built them from the driver's own column list or from a class's declared
 * properties, and a caller-supplied sort column is checked for membership in that list before it is used —
 * an unknown one is dropped, not quoted. `%` and `_` inside the term are deliberately left as wildcards
 * rather than escaped: LIKE has no portable escape character (sqlite has none by default, MySQL uses
 * backslash, ANSI needs an explicit ESCAPE clause), so escaping "portably" means breaking search on some
 * driver, and an operator who types `%` into an admin search box wants a wildcard.
 *
 * EVERY LISTING IS ORDERED, EVEN WHEN NOBODY ASKED. With no ORDER BY, a paged query's row order is whatever
 * the storage engine finds convenient, and it is allowed to differ between the query for page 1 and the query
 * for page 2 — so a row can appear on both pages while another appears on neither, and the operator sees a
 * table that is missing records that are actually there. When no sort is requested the identifier is used,
 * which is stable and always indexed.
 */
final class DataQueryEngine
{
    /**
     * OR-ing a LIKE across every text column of a wide table produces a query no index can help with; past a
     * dozen columns the page is slow enough that an operator will assume it hung. Search covers the first
     * twelve searchable columns in schema order.
     */
    public const int MAX_SEARCH_COLUMNS = 12;

    /**
     * Cell values are truncated in a LISTING (and never in a detail view). A `text` column holding a 2 MB
     * document is legal, and twenty-five of them is a fifty-megabyte HTML response that helps nobody: a table
     * cell can show a couple of hundred characters. The truncation is marked with a horizontal ellipsis so
     * the view is not claiming the value ended there, and the detail page shows the whole thing.
     */
    public const int LIST_VALUE_LIMIT = 200;

    private const string ELLIPSIS = "\u{2026}";

    public function __construct(private readonly RepositoryIntrospector $introspector) {}

    /**
     * One page of a resource. Never throws: a failure comes back as a DataListing carrying a safe reason.
     *
     * The repository is passed IN rather than resolved here so that container resolution — which runs a
     * constructor and can therefore fail for reasons that have nothing to do with querying — has exactly one
     * home, in DataBrowser, shared with the write path.
     *
     * @param  CrudRepository<object, mixed>  $repository
     * @param  list<DataFilter>  $filters
     */
    public function list(
        CrudRepository $repository,
        DataResource $resource,
        DataSchema $schema,
        int $page,
        int $perPage,
        ?string $sort,
        string $direction,
        ?string $search,
        array $filters = [],
    ): DataListing {
        $sort = $this->sortColumn($schema, $sort);
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $term = $this->term($search);

        // The PROJECTION is inside the try as well as the fetch. It reads attributes off hydrated entities
        // and stringifies whatever it finds, which is not obviously fallible until a model's accessor or a
        // value object's __toString throws — and a half-rendered page is exactly as broken as a failed query.
        try {
            [$entities, $total] = $this->fetch($repository, $schema, $page, $perPage, $sort, $direction, $term, $filters);

            $rows = [];
            foreach ($entities as $entity) {
                $rows[] = $this->project($entity, $schema, self::LIST_VALUE_LIMIT);
            }
        } catch (Throwable $e) {
            return DataListing::failure($this->safeReason('The listing query failed', $e), $resource, $schema, $page, $perPage);
        }

        return new DataListing($resource, $schema, $rows, $total, $page, $perPage, $sort, $direction, $term, null, $filters);
    }

    /**
     * One record, or null when it cannot be shown.
     *
     * NULL IS DELIBERATELY AMBIGUOUS HERE, unlike in `list()`. It covers "no such row", "the identifier could
     * not be derived" and "the lookup threw", and it does so on purpose: a detail view that distinguished
     * "this row does not exist" from "this row exists but the query failed" is an existence oracle for
     * anything the caller can name, and the correct rendering for all three is the same 404 page anyway.
     *
     * @param  CrudRepository<object, mixed>  $repository
     */
    public function find(CrudRepository $repository, DataResource $resource, DataSchema $schema, int|string $id): ?DataRecord
    {
        if ($schema->identifier === null) {
            return null;
        }

        try {
            $entity = $repository->findById($id);

            return $entity === null
                ? null
                : new DataRecord($resource, $schema, $id, $this->project($entity, $schema, null));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pick the page of entities and the grand total, by whichever of the four paths this repository supports.
     *
     * @param  CrudRepository<object, mixed>  $repository
     * @param  list<DataFilter>  $filters
     * @return array{0: list<object>, 1: int}
     */
    private function fetch(
        CrudRepository $repository,
        DataSchema $schema,
        int $page,
        int $perPage,
        ?string $sort,
        string $direction,
        ?string $term,
        array $filters = [],
    ): array {
        $pageable = new Pageable($page, $perPage, $this->sort($sort, $direction, $schema));

        if (($term !== null || $filters !== []) && $repository instanceof EloquentRepository) {
            $specifications = [];

            if ($term !== null) {
                $columns = $this->searchColumns($schema);
                if ($columns === []) {
                    return [[], 0];
                }
                $specifications[] = $this->searchSpecification($columns, $term);
            }

            foreach ($filters as $filter) {
                $specifications[] = $this->filterSpecification($filter);
            }

            // AND throughout, so a search inside a relation's listing narrows that relation rather than
            // escaping it, and a second filter narrows the first — the same reasoning that keeps the
            // search's OR group nested.
            /** @var Page<object> $result */
            $result = $repository->findBySpecificationPaged(Specifications::allOf(...$specifications), $pageable);

            return [$result->items, $result->total];
        }

        if ($term === null && $filters === [] && $repository instanceof PagingAndSortingRepository) {
            /** @var Page<object> $result */
            $result = $repository->findPaged($pageable);

            return [$result->items, $result->total];
        }

        return $this->fetchInPhp($repository, $schema, $page, $perPage, $sort, $direction, $term, $filters);
    }

    /**
     * One filter as a predicate on the repository's own builder, so anything its `query()` seam already
     * constrained still holds.
     *
     * EVERY COMPARISON BINDS. The column has already been validated against the schema by the caller, and
     * the value is passed as a parameter in every branch — including the LIKE ones, where the wildcards are
     * added around an escaped value rather than by interpolating the value into a pattern. The result is
     * that what an operator can express is exactly these eight comparisons over exactly the columns the
     * resource publishes, and nothing about a hand-edited URL widens either.
     *
     * The comparisons are LOOSE on type, because a value arrives from a URL and is therefore always a string
     * while the column may be an integer or a decimal. Binding it as-is lets the database do the coercion it
     * would do for `where id = '7'` anyway.
     *
     * @return Specification<Model>
     */
    private function filterSpecification(DataFilter $filter): Specification
    {
        return Specifications::where(static function (Builder $query) use ($filter): void {
            $escaped = self::escapeLike($filter->value);

            match ($filter->operator) {
                DataFilter::NE => $query->where($filter->column, '!=', $filter->value),
                DataFilter::CONTAINS => self::like($query, $filter->column, '%'.$escaped.'%'),
                DataFilter::STARTS => self::like($query, $filter->column, $escaped.'%'),
                DataFilter::GT => $query->where($filter->column, '>', $filter->value),
                DataFilter::LT => $query->where($filter->column, '<', $filter->value),
                DataFilter::NULL => $query->whereNull($filter->column),
                DataFilter::NOT_NULL => $query->whereNotNull($filter->column),
                default => $query->where($filter->column, '=', $filter->value),
            };
        });
    }

    /**
     * A LIKE that treats the user's `%` and `_` as literals.
     *
     * ESCAPING ALONE WAS WORSE THAN NOT ESCAPING. Backslash-escaping the wildcards and then emitting a plain
     * `LIKE ?` means the driver has no escape character declared, so `ada\_love` is matched literally — a
     * search for `ada_love` returned ZERO rows against a table that contained `ada_lovelace@example.test`.
     * Suppressing the wildcards worked; finding anything containing an underscore stopped working, silently.
     * The `ESCAPE` clause is what makes the backslash mean "the next character is a literal", and every
     * driver this framework supports understands it.
     *
     * The COLUMN is wrapped by the grammar rather than interpolated raw. It has already been validated
     * against the schema by the caller, so this is belt-and-braces — but a raw identifier inside a
     * `whereRaw` is exactly the shape that stops being safe the day someone loosens the validation.
     *
     * @param  Builder<Model>  $query
     */
    private static function like(Builder $query, string $column, string $pattern, string $boolean = 'and'): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        // Raw because neither `where(…, 'like', …)` nor Laravel 13's own `whereLike()` emits an ESCAPE
        // clause — both compile to a bare `LIKE ?`, which is precisely the shape that made an escaped
        // underscore match nothing. PHPStan wants a literal-string here and cannot see that $wrapped came
        // from the grammar's own quoting of a column the caller already checked against DataSchema::
        // filterable(); the VALUE is a binding either way.
        // @phpstan-ignore argument.type
        $query->whereRaw($wrapped." like ? escape '\\'", [$pattern], $boolean);
    }

    /** Backslash-escapes the LIKE metacharacters, for use with the ESCAPE clause above. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * The fallback: materialise everything, then filter, sort and slice in PHP. See the class docblock for
     * what this costs — it is the price of browsing a repository that cannot page, and it is charged in full
     * on the first page.
     *
     * @param  CrudRepository<object, mixed>  $repository
     * @param  list<DataFilter>  $filters
     * @return array{0: list<object>, 1: int}
     */
    private function fetchInPhp(
        CrudRepository $repository,
        DataSchema $schema,
        int $page,
        int $perPage,
        ?string $sort,
        string $direction,
        ?string $term,
        array $filters = [],
    ): array {
        $needle = $term === null ? null : mb_strtolower($term);
        $columns = $this->searchColumns($schema);

        $matched = [];
        foreach ($repository->findAll() as $entity) {
            $values = $this->rawValues($entity, $schema);

            if ($needle !== null && ! $this->matches($values, $columns, $needle)) {
                continue;
            }

            // The same eight comparisons the SQL path applies, so a repository that cannot page is filtered
            // by the same rules as one that can — two implementations of one predicate would drift, and the
            // drift would show as the same filter meaning different things on different resources.
            if (! $this->passes($values, $filters)) {
                continue;
            }

            $matched[] = ['entity' => $entity, 'values' => $values];
        }

        if ($sort !== null) {
            // The same ordering the actuator listings use, from the same class — a sort drifts as quietly as
            // a filter does, and the drift would show as one column header meaning two different orders on
            // two pages of one dashboard. It is asked for the COLUMN, once, rather than pair by pair: a
            // column that mixes numbers with anything else has no consistent pairwise answer (see
            // RowComparator::forColumn()), and an inconsistent comparison here reorders the rows that
            // straddle a page boundary between one request and the next. Emptiness is ranked OUTSIDE the
            // direction flip: an em-dash is the absence of a value rather than a value that sorts low, so it
            // stays last under `desc` too.
            $values = array_map(static fn (array $row): mixed => $row['values'][$sort] ?? null, $matched);
            $compare = $sort === $schema->identifier
                ? RowComparator::forIdentity($values)
                : RowComparator::forColumn($values);

            // The same tiebreak the SQL paths get, for the same reason: `usort` is stable in PHP 8, but the
            // ARRAY it is stabilising is rebuilt from the repository on every request, so stability of the
            // sort is not stability of the page boundary. See sort() above. Like the empty rank above it,
            // and for the same reason, it sits OUTSIDE the direction flip: an identity is not a second
            // ordering. It is appended only when the identifier is not already the sort column, for the
            // same reason `ORDER BY id DESC, id ASC` is not emitted.
            //
            // THE TIEBREAK IS A COLUMN TOO, AND IS CHOSEN THE SAME WAY — `forColumn()` over everything the
            // identifier column holds, never `compare()` pair by pair. `Table\InMemoryListing::order()`
            // does exactly this beside it, and RowComparator says why: a per-pair choice between the
            // arithmetic and the natural comparison can close a cycle (`1.10 < 1.9 < 1.9-beta < 1.10` on a
            // column of versions), and an ordering that is not a strict weak ordering entitles `usort()` to
            // answer anything at all. An inconsistent tiebreak breaks the whole ordering just as thoroughly
            // as an inconsistent primary, which is the instability this branch exists to remove.
            $tiebreak = $schema->identifier;
            $breakTie = $tiebreak === null || $tiebreak === $sort ? null : RowComparator::forIdentity(array_map(
                static fn (array $row): mixed => $row['values'][$tiebreak] ?? null,
                $matched,
            ));

            usort($matched, static function (array $a, array $b) use ($sort, $direction, $compare, $tiebreak, $breakTie): int {
                $left = $a['values'][$sort] ?? null;
                $right = $b['values'][$sort] ?? null;

                $rank = RowComparator::rankEmpty($left, $right);

                if ($rank !== 0) {
                    return $rank;
                }

                $comparison = $compare($left, $right);

                if ($direction === 'desc') {
                    $comparison = -$comparison;
                }

                // `$breakTie` is null for exactly the two cases that have no tiebreak to apply — no
                // identifier, or an identifier that IS the sort column — so testing it tests both.
                return $comparison !== 0 || $breakTie === null
                    ? $comparison
                    : $breakTie($a['values'][$tiebreak] ?? null, $b['values'][$tiebreak] ?? null);
            });
        }

        $total = count($matched);
        $slice = array_slice($matched, ($page - 1) * $perPage, $perPage);

        return [array_map(static fn (array $row): object => $row['entity'], $slice), $total];
    }

    /**
     * A grouped `(col LIKE ? OR col LIKE ? ...)` predicate over the repository's own builder.
     *
     * The OR group is NESTED rather than chained onto the outer builder: `->orWhere()` at the top level would
     * escape any constraint the repository's `query()` seam had already applied, turning `tenant = 7 AND
     * (name LIKE …)` into `tenant = 7 OR name LIKE …` — every tenant's rows, from a search box.
     *
     * @param  non-empty-list<string>  $columns
     * @return Specification<Model>
     */
    private function searchSpecification(array $columns, string $term): Specification
    {
        return Specifications::where(static function (Builder $query) use ($columns, $term): void {
            $query->where(static function (Builder $group) use ($columns, $term): void {
                foreach ($columns as $column) {
                    self::like($group, $column, '%'.self::escapeLike($term).'%', 'or');
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<DataFilter>  $filters
     */
    private function passes(array $values, array $filters): bool
    {
        foreach ($filters as $filter) {
            $value = $values[$filter->column] ?? null;
            $string = is_scalar($value) ? (string) $value : null;

            $ok = match ($filter->operator) {
                DataFilter::NE => $string !== $filter->value,
                DataFilter::CONTAINS => $string !== null && str_contains(mb_strtolower($string), mb_strtolower($filter->value)),
                DataFilter::STARTS => $string !== null && str_starts_with(mb_strtolower($string), mb_strtolower($filter->value)),
                // The SCALAR STRING, not the raw value: see compare() for why a bool must reach it as the
                // `'1'`/`''` the driver would have bound and not as the word the listing renders.
                DataFilter::GT => $string !== null && $this->compare($string, $filter->value) > 0,
                DataFilter::LT => $string !== null && $this->compare($string, $filter->value) < 0,
                DataFilter::NULL => $value === null,
                DataFilter::NOT_NULL => $value !== null,
                default => $string === $filter->value,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $columns
     */
    private function matches(array $values, array $columns, string $needle): bool
    {
        foreach ($columns as $column) {
            $value = $values[$column] ?? null;
            if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function searchColumns(DataSchema $schema): array
    {
        return array_slice($schema->searchable(), 0, self::MAX_SEARCH_COLUMNS);
    }

    /**
     * A requested sort survives only if the schema knows the column; otherwise the identifier is used, and
     * only when there is neither does a listing go out unordered — see the class docblock on why that is the
     * last resort and not the default.
     */
    private function sortColumn(DataSchema $schema, ?string $requested): ?string
    {
        $sortable = $schema->sortable();

        if ($requested !== null && in_array($requested, $sortable, true)) {
            return $requested;
        }

        return $schema->identifier !== null && in_array($schema->identifier, $sortable, true)
            ? $schema->identifier
            : null;
    }

    /**
     * The ORDER BY a listing goes out with: what was asked for, then the identifier as a tiebreak.
     *
     * EVERY LISTING IS ORDERED, EVEN WHEN NOBODY ASKED — that much was already true, and it is why
     * sortColumn() falls back to the identifier. What was missing is the second half. Ordering by a column
     * with duplicate values leaves the tied rows in whatever order the engine finds convenient, and it is
     * allowed to find a different one convenient for the query behind page 1 and the query behind page 2:
     * a row is then returned on both and another on neither, and the operator reads a table that is missing
     * records which are really there. `ORDER BY status DESC, id ASC` has no such freedom.
     *
     * THE TIEBREAK IS ALWAYS ASCENDING. It is an identity, not a second ordering — mirroring it with the
     * primary direction would make the order WITHIN a tie depend on the direction it is breaking the tie
     * inside, which is the same instability with extra steps. And it is appended only when the identifier
     * is not already the sort column, because `ORDER BY id DESC, id ASC` is at best noise and at worst an
     * index the planner declines to use.
     */
    private function sort(?string $column, string $direction, DataSchema $schema): ?Sort
    {
        if ($column === null) {
            return null;
        }

        $sort = Sort::by($column);
        if ($direction === 'desc') {
            $sort = $sort->descending();
        }

        $identifier = $schema->identifier;

        return $identifier !== null && $identifier !== $column && in_array($identifier, $schema->sortable(), true)
            ? $sort->and(Sort::by($identifier))
            : $sort;
    }

    private function term(?string $search): ?string
    {
        $term = trim($search ?? '');

        return $term === '' ? null : $term;
    }

    /**
     * Read an entity's fields in schema order, WITHOUT masking or formatting — the shape sorting and
     * filtering compare against.
     *
     * Eloquent is read through `getAttributes()` (the raw column values) rather than `getAttribute()` (the
     * cast values) on purpose: this page's job is to show what is in the table, and a cast turns a timestamp
     * into a Carbon object and a JSON column into an array, neither of which is what the row holds. The
     * casts still shaped the COLUMN TYPES (see DataSchemaFactory), which is where they belong — deciding how
     * to render, not deciding what the value is.
     *
     * @return array<string, mixed>
     */
    private function rawValues(object $entity, DataSchema $schema): array
    {
        $attributes = $entity instanceof Model ? $entity->getAttributes() : null;

        $values = [];
        foreach ($schema->columns as $column) {
            $values[$column->name] = $attributes !== null
                ? ($attributes[$column->name] ?? null)
                : $this->introspector->read($entity, $column->name);
        }

        return $values;
    }

    /**
     * Raw values, masked and normalised for display. `$limit` truncates long strings in a listing and is null
     * on a detail view.
     *
     * A NULL IN A SENSITIVE COLUMN STAYS NULL. Replacing it with `******` would tell the reader that a secret
     * is set when none is — which reads as "this account has an API token" and is exactly the kind of quiet
     * falsehood an operator would act on.
     *
     * @return array<string, mixed>
     */
    private function project(object $entity, DataSchema $schema, ?int $limit): array
    {
        $values = [];
        foreach ($this->rawValues($entity, $schema) as $name => $value) {
            $column = $schema->column($name);

            $values[$name] = $column !== null && $column->sensitive && $value !== null
                ? SensitiveValueMasker::MASK
                : $this->normalize($value, $limit);
        }

        return $values;
    }

    /**
     * Reduce a value to something a template can print without calling a method on it. Objects are the
     * interesting case: a Carbon, a backed enum and a value object all reach here from a cast or a plain
     * entity, and a view that has to type-check each one will get it wrong. Anything with no printable form
     * degrades to its class name in brackets, which is information rather than "Object of class X could not
     * be converted to string".
     */
    private function normalize(mixed $value, ?int $limit): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncate($value, $limit);
        }

        if (is_array($value)) {
            return $this->truncate((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $limit);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return $this->truncate((string) $value, $limit);
        }

        return is_object($value) ? '['.$value::class.']' : '[unrenderable]';
    }

    private function truncate(string $value, ?int $limit): string
    {
        if ($limit === null || mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).self::ELLIPSIS;
    }

    /**
     * How `greater than` and `less than` compare on the unpaged path.
     *
     * IT IS GIVEN THE CELL'S SCALAR STRING, NEVER THE RAW VALUE, and the difference is not cosmetic. The
     * paged sibling of this predicate is `where(col, '>', ?)` in SQL: the driver binds a bool as `1`/`0`, so
     * `pinned > 0` selects the pinned rows. RowComparator renders a bool for a READER — `true`/`false` — and
     * a word is not numeric, so the pair would fall to the natural-text comparison where `t` and `f` sort
     * after every digit: `greater than 0` would match EVERY row and `less than 1` none, on a repository that
     * cannot page, while the identical filter over a pageable resource answered correctly. `(string) true`
     * is `'1'` and `(string) false` is `''`, which are the shapes the binding has, so passing the string
     * keeps one filter meaning one thing on both paths. That is the same drift the search predicate above
     * warns about, arriving through the operand rather than through a second implementation.
     *
     * It is RowComparator's value comparison and nothing else — no empty-last rank. In SQL the empty string
     * is simply the smallest string, and ranking empties last here would be the same drift again. Where
     * empties go is a question about a LISTING's order, which is asked in the sort, not here.
     */
    private function compare(string $a, string $b): int
    {
        return RowComparator::compare($a, $b);
    }

    /**
     * A reason a person can read, WITHOUT the exception message: a QueryException's message carries the SQL and
     * its bound values, and this string is rendered on a page. Public because the write path in DataBrowser
     * needs exactly the same guarantee, and two formatters is how one of them ends up calling getMessage().
     *
     * `Illuminate\Database\QueryException::getMessage()` embeds the failing SQL and the bound parameters. On
     * this surface those bindings are a searched term, a primary key, or — on an update — the submitted field
     * values, so echoing the message into HTML publishes the schema and the data in one line.
     *
     * A failure the data layer has already translated (see firefly/data's PersistenceExceptionTranslator) is
     * the one case where more can be said safely — the translated type IS the sentence, and the driver's text
     * is on `previous`, never here. Everything else keeps the withheld-message wording and names the class,
     * which is enough for an operator to know what kind of failure it was and to find it in the log.
     */
    public function safeReason(string $what, Throwable $e): string
    {
        $sentence = match (true) {
            $e instanceof DuplicateKeyException => 'a row with the same unique value already exists.',
            $e instanceof DataIntegrityViolationException => 'the database refused it because a constraint (a foreign key, a NOT NULL or a check) would be broken.',
            $e instanceof CannotAcquireLockException => 'the row is locked by another transaction; try again in a moment.',
            $e instanceof QueryTimeoutException, $e instanceof TransactionTimedOutException => 'the database took longer than its timeout to answer.',
            $e instanceof DataAccessResourceFailureException, $e instanceof TransientDataAccessResourceException => 'the database could not be reached.',
            default => null,
        };

        if ($sentence !== null) {
            return sprintf('%s: %s', $what, $sentence);
        }

        return sprintf(
            '%s (%s). The exception message is withheld because it can contain SQL and bound values; see the application log.',
            $what,
            $e::class,
        );
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use BackedEnum;
use DateTimeInterface;
use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Firefly\Data\Repository\CrudRepository;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\PagingAndSortingRepository;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Repository\Specification\Specification;
use Firefly\Data\Repository\Specification\Specifications;
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
        ?DataFilter $filter = null,
    ): DataListing {
        $sort = $this->sortColumn($schema, $sort);
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $term = $this->term($search);

        // The PROJECTION is inside the try as well as the fetch. It reads attributes off hydrated entities
        // and stringifies whatever it finds, which is not obviously fallible until a model's accessor or a
        // value object's __toString throws — and a half-rendered page is exactly as broken as a failed query.
        try {
            [$entities, $total] = $this->fetch($repository, $schema, $page, $perPage, $sort, $direction, $term, $filter);

            $rows = [];
            foreach ($entities as $entity) {
                $rows[] = $this->project($entity, $schema, self::LIST_VALUE_LIMIT);
            }
        } catch (Throwable $e) {
            return DataListing::failure($this->safeReason('The listing query failed', $e), $resource, $schema, $page, $perPage);
        }

        return new DataListing($resource, $schema, $rows, $total, $page, $perPage, $sort, $direction, $term, null, $filter);
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
        ?DataFilter $filter = null,
    ): array {
        $pageable = new Pageable($page, $perPage, $this->sort($sort, $direction));

        if (($term !== null || $filter !== null) && $repository instanceof EloquentRepository) {
            $specifications = [];

            if ($term !== null) {
                $columns = $this->searchColumns($schema);
                if ($columns === []) {
                    return [[], 0];
                }
                $specifications[] = $this->searchSpecification($columns, $term);
            }

            if ($filter !== null) {
                $specifications[] = $this->filterSpecification($filter);
            }

            // AND, so a search inside a relation's listing narrows that relation rather than escaping it —
            // the same reasoning that keeps the search's OR group nested.
            /** @var Page<object> $result */
            $result = $repository->findBySpecificationPaged(Specifications::allOf(...$specifications), $pageable);

            return [$result->items, $result->total];
        }

        if ($term === null && $filter === null && $repository instanceof PagingAndSortingRepository) {
            /** @var Page<object> $result */
            $result = $repository->findPaged($pageable);

            return [$result->items, $result->total];
        }

        return $this->fetchInPhp($repository, $schema, $page, $perPage, $sort, $direction, $term, $filter);
    }

    /**
     * `column = value`, applied through the repository's own builder so anything its `query()` seam already
     * constrained still holds.
     *
     * The comparison is a LOOSE string one, because the value arrives from a URL and is therefore always a
     * string while the column may be an integer key. Binding it as-is lets the database do the coercion it
     * would do for `where id = '7'` anyway, and keeps the value a bound parameter rather than anything
     * concatenated.
     *
     * @return Specification<Model>
     */
    private function filterSpecification(DataFilter $filter): Specification
    {
        return Specifications::where(static function (Builder $query) use ($filter): void {
            $query->where($filter->column, '=', $filter->value);
        });
    }

    /**
     * The fallback: materialise everything, then filter, sort and slice in PHP. See the class docblock for
     * what this costs — it is the price of browsing a repository that cannot page, and it is charged in full
     * on the first page.
     *
     * @param  CrudRepository<object, mixed>  $repository
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
        ?DataFilter $filter = null,
    ): array {
        $needle = $term === null ? null : mb_strtolower($term);
        $columns = $this->searchColumns($schema);

        $matched = [];
        foreach ($repository->findAll() as $entity) {
            $values = $this->rawValues($entity, $schema);

            if ($needle !== null && ! $this->matches($values, $columns, $needle)) {
                continue;
            }

            // Loose, for the same reason the SQL path binds a string: the value came from a URL and the
            // column is as likely to be an int key as a string.
            if ($filter !== null && ! $this->equals($values[$filter->column] ?? null, $filter->value)) {
                continue;
            }

            $matched[] = ['entity' => $entity, 'values' => $values];
        }

        if ($sort !== null) {
            usort($matched, function (array $a, array $b) use ($sort, $direction): int {
                $comparison = $this->compare($a['values'][$sort] ?? null, $b['values'][$sort] ?? null);

                return $direction === 'desc' ? -$comparison : $comparison;
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
        $pattern = '%'.$term.'%';

        return Specifications::where(static function (Builder $query) use ($columns, $pattern): void {
            $query->where(static function (Builder $group) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $group->orWhere($column, 'like', $pattern);
                }
            });
        });
    }

    private function equals(mixed $value, string $expected): bool
    {
        return is_scalar($value) && (string) $value === $expected;
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

    private function sort(?string $column, string $direction): ?Sort
    {
        if ($column === null) {
            return null;
        }

        $sort = Sort::by($column);

        return $direction === 'desc' ? $sort->descending() : $sort;
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

    /** Null-last ordering, so a nullable column does not sort its empties into the middle of the values. */
    private function compare(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        if (is_scalar($a) && is_scalar($b)) {
            return is_numeric($a) && is_numeric($b) ? ($a + 0) <=> ($b + 0) : strnatcasecmp((string) $a, (string) $b);
        }

        return 0;
    }

    /**
     * A failure sentence that names the exception's CLASS and withholds its message. Public because the write
     * path in DataBrowser needs exactly the same guarantee, and two formatters is how one of them ends up
     * calling getMessage().
     *
     * `Illuminate\Database\QueryException::getMessage()` embeds the failing SQL and the bound parameters. On
     * this surface those bindings are a searched term, a primary key, or — on an update — the submitted field
     * values, so echoing the message into HTML publishes the schema and the data in one line. The class name
     * is enough for an operator to know what kind of failure it was and to find it in the log.
     */
    public function safeReason(string $what, Throwable $e): string
    {
        return sprintf(
            '%s (%s). The exception message is withheld because it can contain SQL and bound values; see the application log.',
            $what,
            $e::class,
        );
    }
}

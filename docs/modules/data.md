# Data & Repositories

`firefly/data` is LaraFly's repository and query layer over Eloquent: Spring-Data-style CRUD/paging ports, an
extended-Spring **derived-query** grammar parsed straight from the method name, an explicit `#[Query]` escape
hatch, and composable `Specification`s — plus the `#[Transactional]` interception core documented separately
in [Transactions](transactional.md). The derived-query parser and the Eloquent dispatch it drives are both
**reflection-free**; a dedicated test confines every reflection site in `packages/data/src` to exactly two
sanctioned files (`TransactionalScanner` at scan time, `ProxyFactory` for proxy state-copy) — nothing else in
this page's surface touches reflection.

## The ports: `CrudRepository` / `PagingAndSortingRepository`

```php
/**
 * @template TEntity of object
 * @template TId
 */
interface CrudRepository
{
    public function save(object $entity): object;
    public function saveAll(iterable $entities): array;
    public function findById(mixed $id): ?object;
    public function findAll(): array;
    public function findAllById(iterable $ids): array;
    public function existsById(mixed $id): bool;
    public function count(): int;
    public function delete(object $entity): void;
    public function deleteById(mixed $id): void;
    public function deleteAll(): void;
}
```

PHP has no runtime generics, so the signatures are deliberately `object`/`mixed`/`array` — the `@template
TEntity`/`@template TId` docblock parameters (plus `@param`/`@return` tags naming `TEntity`/`list<TEntity>`)
give PHPStan the precise entity and id types back.

`PagingAndSortingRepository extends CrudRepository` adds paging and sorting. Spring overloads a single
`findAll(Pageable)`/`findAll(Sort)`; PHP has no method overloading, so the two are distinct, cleanly-typed
methods instead:

```php
interface PagingAndSortingRepository extends CrudRepository
{
    public function findPaged(Pageable $pageable): Page;
    public function findSorted(Sort $sort): array;
}
```

`EloquentRepository` (see [Relational Data](data-relational.md)) is the Eloquent-backed implementation of
both ports; an application repository extends `EloquentRepository`, not these interfaces directly.

## Derived queries

A repository method whose name follows the grammar below needs no body at all — it is resolved dynamically by
`EloquentRepository::__call()`, which parses the method name with `DerivedQueryParser::parse()` (pure string
parsing, no reflection, the method name is the only input) and drives the Eloquent `Builder` accordingly.

### Grammar

| Piece | Values | Notes |
|---|---|---|
| Prefix | `findBy` / `countBy` / `existsBy` / `deleteBy` | `find` also accepts `findFirstBy…` (limit 1), `findTop{N}By…` (limit N), `findDistinctBy…` (adds `DISTINCT`) before the `By`. |
| Connector | `And` / `Or` | Splits predicate groups; only recognized before a new CamelCase field, so a field that merely *starts with* `Or`/`And` (e.g. `OrderId`) is left intact. |
| Operator | see table below | Matched **longest-first** against the end of each predicate group; `Equals` (no token) is the implicit default. |
| Case modifier | `IgnoreCase` suffix | Routes the equality/LIKE family through `LOWER(col) op LOWER(?)`. |
| Ordering | trailing `OrderBy{Field}{Asc\|Desc}` | Chainable (`OrderByStatusAscAmountDesc`); direction defaults to `asc` if omitted. |

Operators, in the **exact longest-match-first order** the parser walks (reordering this list is the
fault-injection tripwire the parser's own tests guard against — e.g. shortest-first would parse
`StatusNotIn` as field `StatusNot` op `In`):

| Operator | SQL effect | Args consumed |
|---|---|---|
| `GreaterThanEqual` | `>=` | 1 |
| `LessThanEqual` | `<=` | 1 |
| `GreaterThan` | `>` | 1 |
| `LessThan` | `<` | 1 |
| `Between` | `WHERE col BETWEEN ? AND ?` | 2 |
| `NotLike` | `not like` | 1 |
| `Like` | `like` | 1 |
| `NotIn` | `WHERE col NOT IN (?)` | 1 (array/iterable) |
| `In` | `WHERE col IN (?)` | 1 (array/iterable) |
| `Containing` | `like '%value%'` | 1 |
| `StartingWith` | `like 'value%'` | 1 |
| `EndingWith` | `like '%value'` | 1 |
| `IsNotNull` | `WHERE col IS NOT NULL` | 0 |
| `IsNull` | `WHERE col IS NULL` | 0 |
| `Not` | `!=` | 1 |
| `True` | `WHERE col = true` | 0 |
| `False` | `WHERE col = false` | 0 |
| *(none)* | `Equals` → `=` | 1 |

Each CamelCase field segment is mapped to a `snake_case` column (`AmountGreaterThan` → `amount`), and every
derived column is validated against a bare-identifier pattern before it can reach a query — the method name
is untrusted input reachable through the public `__call`, and the `IgnoreCase` path interpolates the column
into a raw `LOWER(col)` fragment, so this validation is what keeps that fragment injection-free. Arguments
bind to predicates **in declaration order**, left to right, advancing a cursor by however many values each
operator consumes (`Between` consumes two, `IsNull`/`True`/`False` consume none, everything else consumes
one):

```php
/**
 * @extends EloquentRepository<Record>
 *
 * @method list<Record> findByStatusAndAmountGreaterThan(string $status, int $amount)
 * @method list<Record> findTop2ByStatusOrderByAmountDesc(string $status)
 * @method bool existsByEmailIgnoreCase(string $email)
 * @method int countByStatus(string $status)
 * @method int deleteByStatus(string $status)
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;
}

$repo->findByStatusAndAmountGreaterThan('open', 100);        // WHERE status = ? AND amount > ?
$repo->findTop2ByStatusOrderByAmountDesc('open');             // WHERE status = ? ORDER BY amount desc LIMIT 2
$repo->existsByEmailIgnoreCase('b@x.test');                   // WHERE LOWER(email) = LOWER(?)
```

`@method` tags on the class (not a real method body) are how PHPStan sees a typed return for what is, at
runtime, dynamic `__call` dispatch.

## The `#[Query]` explicit-query escape hatch

A method can instead declare its SQL directly, bypassing the derived-query grammar entirely:

```php
final class Query
{
    public function __construct(
        public string $sql,
        public bool $native = false,
    ) {}
}
```

```php
#[Query('select * from records where email = :email order by amount asc')]
public function findByEmailRaw(string $email): array
{
    return $this->dispatchQuery(__FUNCTION__, func_get_args());
}
```

Named `:param` placeholders are rewritten to positional `?` in appearance order and the method's arguments
bind positionally. `#[Query]` methods are discovered by the same `TransactionalScanner` that compiles the
`#[Transactional]` manifest (see [Transactions](transactional.md)), so a declared `#[Query]` method's SQL is
resolved from the compiled manifest with no per-request reflection; both the derived-query path and a
declared `#[Query]` method funnel through the single `dispatchQuery()` method shared by `__call` and any
method body that calls it explicitly — explicit SQL from the manifest wins over a derived-query parse when
both would otherwise apply to the same method name.

## `Specification` composition

A `Specification<TModel>` is a composable predicate over an Eloquent `Builder`:

```php
/** @template TModel of Model */
interface Specification
{
    public function toBuilder(Builder $query): Builder;
}
```

`Specifications` is the static factory (interfaces can't carry static factory bodies in PHP):

```php
Specifications::allOf(...$specifications); // AND-folds left-to-right; zero-arg = match-all
Specifications::anyOf(...$specifications); // OR-folds left-to-right; zero-arg = match-all
Specifications::not($specification);
Specifications::where(fn (Builder $q) => $q->where('status', 'open'));
```

Each combinator groups its side in a nested closure so precedence survives further composition —
`AndSpecification` emits `->where(fn ($inner) => ...)->where(fn ($inner) => ...)`, `OrSpecification` emits
`->where(...)->orWhere(...)`, and `NotSpecification` wraps its inner specification in `->whereNot(...)`.
Apply a built specification with:

```php
public function findBySpecification(Specification $specification): array;
public function findBySpecificationPaged(Specification $specification, Pageable $pageable): Page;
```

## Pagination value objects

`Page<T>` — one page of results plus the grand total, built at the Eloquent edge from a sliced fetch plus a
count query:

```php
final readonly class Page
{
    public function __construct(
        public array $items,
        public int $total,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function totalPages(): int;      // ceil(total / size)
    public function hasNext(): bool;
    public function hasPrevious(): bool;
    public function numberOfElements(): int;
    public function map(callable $mapper): self;  // transforms items, preserves paging metadata
}
```

`Pageable` — a page request (1-based page number, size, optional `Sort`):

```php
final readonly class Pageable
{
    public function __construct(public int $page = 1, public int $size = 20, public ?Sort $sort = null) {}

    public static function of(int $page, int $size, ?Sort $sort = null): self;
    public static function unpaged(?Sort $sort = null): self;  // PHP_INT_MAX size sentinel; isPaged() reports false

    public function offset(): int;    // zero-based row offset, (page - 1) * size
    public function next(): self;
    public function previous(): self; // clamps to page 1
}
```

`Sort`/`Order`/`Direction` — an immutable, composable ordering:

```php
final readonly class Sort
{
    public static function unsorted(): self;
    public static function by(string ...$properties): self;  // ascending over each property

    public function and(self $other): self;         // concatenates
    public function ascending(): self;               // rewrites every order to Asc
    public function descending(): self;              // rewrites every order to Desc
}

final readonly class Order
{
    public function __construct(public string $property, public Direction $direction = Direction::Asc) {}

    public static function asc(string $property): self;
    public static function desc(string $property): self;
}

enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
```

`Direction`'s backing value **is** the Eloquent `orderBy()` direction string, so the mapping at the Eloquent
edge is a bare `->orderBy($order->property, $order->direction->value)` with no translation table.

---

## Browsing what a repository holds

`firefly/admin` ships a [data browser](data-browser.md) that discovers its resources from exactly these ports:
any bean whose scan-time interface list contains `CrudRepository` is browsable, and a repository that also
implements `PagingAndSortingRepository` is paged **in the database** rather than in PHP — which on a large table
is the difference between one page of rows and an out-of-memory.

It is **disabled by default** and does not follow `app.debug` or `firefly.admin.enabled`; writes need a second
key on top of that, and there is deliberately no create. See [Data Browser](data-browser.md) for the reasoning.

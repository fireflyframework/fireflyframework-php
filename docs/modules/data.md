# Data & Repositories

`firefly/data` is LaraFly's repository and query layer over Eloquent: Spring-Data-style CRUD/paging ports, an
extended-Spring **derived-query** grammar parsed straight from the method name, an explicit `#[Query]` escape
hatch, composable `Specification`s, query by example, and the attribute-driven `#[Modifying]`/`#[Projection]`/
`#[Lock]`/`#[EntityGraph]` methods — plus the `#[Transactional]` interception core documented separately in
[Transactions](transactional.md). The derived-query parser and the Eloquent dispatch it drives are both
**reflection-free**; a dedicated test confines every reflection site in `packages/data/src` to exactly two
sanctioned files (`TransactionalScanner` at scan time, `ProxyFactory` for proxy state-copy) — nothing else in
this page's surface touches reflection.

## The ports: `CrudRepository` / `PagingAndSortingRepository`

The port itself, with the class's `@template` docblock and each method's `@param`/`@return` tags cut:

<!-- source: packages/data/src/Repository/CrudRepository.php -->
```php
interface CrudRepository
{
    // …
    public function save(object $entity): object;
    // …
    public function saveAll(iterable $entities): array;
    // …
    public function findById(mixed $id): ?object;
    // …
    public function findAll(): array;
    // …
    public function findAllById(iterable $ids): array;
    // …
    public function existsById(mixed $id): bool;

    public function count(): int;
    // …
    public function delete(object $entity): void;
    // …
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

<!-- source: packages/data/src/Repository/PagingAndSortingRepository.php -->
```php
interface PagingAndSortingRepository extends CrudRepository
{
    // …
    public function findPaged(Pageable $pageable): Page;
    // …
    public function findSlice(Pageable $pageable): Slice;
    // …
    public function findSorted(Sort $sort): array;
}
```

`findSlice()` runs no count query — see [Slices](#slices) below.

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
one). The package's own derived-query fixture is the shape, with its declared methods cut away so that only the
class header and the `@method` tags that type them remain:

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/**
 * A concrete #[Repository] over Record with derived-query methods (resolved by __call, typed for PHPStan via the
 * method-tag hints below), one explicit #[Query] method, and one DECLARED method per repository attribute the
 * scanner records — every declared body is the one-line dispatchQuery() delegation, so what differs between
 * them is only the attribute.
 *
 * @extends EloquentRepository<Record>
 *
 * @method list<Record> findByStatusAndAmountGreaterThan(string $status, int $amount)
 * @method list<Record> findTop2ByStatusOrderByAmountDesc(string $status)
 * @method bool existsByEmailIgnoreCase(string $email)
 * @method int countByStatus(string $status)
 * @method int deleteByStatus(string $status)
 * @method Page<Record> findByStatus(string $status, Pageable $pageable)
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;
    // …
}
```

Calling those methods is what the grammar buys:

<!-- illustrative: the calls a reader makes against their own repository; a derived method has no body to point at -->
```php
$repo->findByStatusAndAmountGreaterThan('open', 100);        // WHERE status = ? AND amount > ?
$repo->findTop2ByStatusOrderByAmountDesc('open');             // WHERE status = ? ORDER BY amount desc LIMIT 2
$repo->existsByEmailIgnoreCase('b@x.test');                   // WHERE LOWER(email) = LOWER(?)
```

`@method` tags on the class (not a real method body) are how PHPStan sees a typed return for what is, at
runtime, dynamic `__call` dispatch.

## The `#[Query]` explicit-query escape hatch

A method can instead declare its SQL directly, bypassing the derived-query grammar entirely:

<!-- source: packages/data/src/Repository/Attributes/Query.php -->
```php
#[Attribute(Attribute::TARGET_METHOD)]
final class Query
{
    public function __construct(
        public string $sql,
        public bool $native = false,
    ) {}
}
```

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
#[Query('select * from records where email = :email order by amount asc')]
public function findByEmailRaw(string $email): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<array<string, mixed>> $rows */
    return $rows;
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

<!-- source: packages/data/src/Repository/Specification/Specification.php -->
```php
interface Specification
{
    // …
    public function toBuilder(Builder $query): Builder;
}
```

`Specifications` is the static factory (interfaces can't carry static factory bodies in PHP):

<!-- illustrative: the four factory calls a reader makes from their own code -->
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

<!-- source: packages/data/src/Repository/EloquentRepository.php -->
```php
public function findBySpecification(Specification $specification): array
{
    $query = $this->reading(__FUNCTION__);

    return $this->translating(fn (): array => $this->narrow($specification->toBuilder($query)->get()->all()));
}
// …
public function findBySpecificationPaged(Specification $specification, Pageable $pageable): Page
{
    $query = $this->reading(__FUNCTION__);

    return $this->translating(fn (): Page => $this->pageOf($specification->toBuilder($query), $pageable));
}
```

## Query by example

`Example` is Spring Data's `Example<T>` — and it **is a `Specification`**, so it drives `findBySpecification()`
directly and composes with `Specifications::allOf()`/`anyOf()`/`not()` like any other predicate. The probe is an
Eloquent model with some attributes set (only what was set, not every column — `new Order(['status' => 'open'])`
is a one-property probe), a plain object (its public properties) or a plain `column => value` array; the
`ExampleMatcher` carries the rules:

<!-- illustrative: the probe, the matcher and the six calls a reader writes against their own repository -->
```php
$repo->findByExample(Example::of(['status' => 'open']));                       // WHERE status = ?
$repo->findByExample(Example::of(new Order(['status' => 'open', 'customer' => 'Ada'])));

$matcher = ExampleMatcher::matching()                                           // = matchingAll(): AND
    ->withIgnorePaths('id', 'created_at')                                       // dropped from the probe
    ->withStringMatcher(StringMatcher::CONTAINING)                              // every string: LIKE %v%
    ->withIgnoreCase('customer')                                                // no paths = every string
    ->withMatcher('email', GenericPropertyMatcher::startsWith()->caseSensitive())
    ->withIncludeNullValues();                                                  // null probe value => IS NULL

$repo->findByExample(Example::of($probe, $matcher));
$repo->findOneByExample($example);        // null, the row, or IncorrectResultSizeDataAccessException for more
$repo->countByExample($example);
$repo->existsByExample($example);
$repo->findByExamplePaged($example, Pageable::of(1, 20, Sort::by('id')));
```

Rules: `matchingAny()` folds with OR; a `null` probe value is skipped unless `withIncludeNullValues()`; only
**string** values get string matching (`EXACT` = `=`, `CONTAINING`/`STARTING`/`ENDING` = `LIKE`), an int,
float or bool is always `=`; LIKE patterns escape `!`, `%` and `_` with `!` and carry `ESCAPE '!'` — the one
escape spelling that reads the same on sqlite, mysql, pgsql and sqlsrv; `withIgnoreCase()` wraps both sides in
`LOWER()`. Every probe key is validated as a bare identifier at `Example::of()` (an `InvalidArgumentException`
otherwise) because a key can reach a raw fragment — the same guard the derived-query parser applies.

## Attribute-driven repository methods

PHP cannot attach an attribute to a method that is never declared, so the attributes below sit on **declared**
repository methods — a `#[Query]` method, or a derived-query method declared with the one-line body
`return $this->dispatchQuery(__FUNCTION__, func_get_args());`. The `TransactionalScanner` records them into
the compiled manifest's `repositories` map (a `#[Projection]`'s DTO constructor is reflected **once**, at scan
time); `EloquentRepository` honours them at dispatch with no reflection. A `#[Repository]` that is also
`#[Transactional]` runs as its generated proxy subclass; lookups strip the proxy suffix, so the attributes work
there too.

### `#[Modifying]`

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/** A statement that needs a transaction: returns the affected-row count. */
#[Modifying]
#[Query('update records set status = :status where amount < :amount')]
public function closeSmall(string $status, int $amount): int
{
    $affected = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_int($affected));

    return $affected;
}

/** A statement that may run without a transaction. */
#[Modifying(requiresTransaction: false)]
#[Query('delete from records where status = :status')]
public function purgeStatus(string $status): int
{
    $affected = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_int($affected));

    return $affected;
}
```

The SQL runs as a **statement** (`Connection::affectingStatement()`) and the method returns the affected-row
count. Two rules, both refused at scan time (a `ConfigurationException` from `firefly:cache` or the first
boot): `#[Modifying]` needs a `#[Query]` (a derived `deleteBy…` is already a statement), and the SQL must not
be a `SELECT`/`WITH`/`VALUES`. The statement refuses to run outside an active transaction on the model's
connection (`TransactionRequiredException`) unless `#[Modifying(requiresTransaction: false)]`.
`clearAutomatically` is accepted for source compatibility and does nothing — Eloquent has no persistence
context to clear.

### `#[Projection]`

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/**
 * A derived query hydrated into a DTO; the SELECT list is inferred from RecordSummary's constructor.
 *
 * @return list<RecordSummary>
 */
#[Projection(RecordSummary::class)]
public function findByStatusOrderByAmountAsc(string $status): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<RecordSummary> $rows */
    return $rows;
}

/**
 * Explicit SQL hydrated into the same DTO; the SQL owns the select list.
 *
 * @return list<RecordSummary>
 */
#[Projection(RecordSummary::class)]
#[Query('select id, email, amount from records where status = :status order by amount asc')]
public function summariesByStatusRaw(string $status): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<RecordSummary> $rows */
    return $rows;
}
```

The DTO those two hydrate into is a plain `final readonly` class whose constructor names the columns:

<!-- source: packages/data/tests/Fixtures/Repository/RecordSummary.php -->
```php
/** A class-based projection over three `records` columns; `email` is nullable in the table and here. */
final readonly class RecordSummary
{
    public function __construct(
        public int $id,
        public ?string $email,
        public int $amount,
    ) {}
}
```

Each row is hydrated through the DTO's constructor: snake_case column → camelCase parameter, `int`/`float`/
`string`/`bool` coerced from what the driver returns, a backed enum from its backing value, `DateTimeImmutable`/
`DateTime` from the string; an optional parameter whose column is absent takes its default. Coercion is
**lossless** or refused: `'150.75'` into an `int`, a backing value the enum has no case for, a string that is
not a date — each is a `ConfigurationException` naming the DTO, the column, the value and the parameter, never
a bare `ValueError` or `TypeError`. On a derived method the `SELECT` list is `columns:`
(`#[Projection(RecordSummary::class, columns: ['id', 'email'])]`) or the parameters' columns; on a `#[Query]`
method the SQL owns its select list. A missing required column and a `NULL` into a non-nullable parameter are
`ConfigurationException`s at first use, naming both the column and the parameter (the columns are checked
against the table before the query runs — sqlite would otherwise read an unknown double-quoted identifier as a
string literal). `findFirst…` returns one DTO or null; `count`/`exists`/`delete` ignore a projection.
Interface-style projections (Spring's `interface RecordSummary { String getEmail(); }`) are not offered —
PHP has no proxy that could implement an interface by column name at runtime; declare the DTO.

### `#[Lock]`

`PESSIMISTIC_WRITE` compiles to `lockForUpdate()`, `PESSIMISTIC_READ` to `sharedLock()`:

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/** @return list<Record> */
#[Lock(LockMode::PESSIMISTIC_WRITE)]
public function findByEmail(string $email): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<Record> $rows */
    return $rows;
}

/** @return list<Record> */
#[Lock(LockMode::PESSIMISTIC_READ)]
public function findByAmountBetween(int $low, int $high): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<Record> $rows */
    return $rows;
}
```

`$repo->findByIdForUpdate($id)` is the programmatic twin: `findById()` under `FOR UPDATE`.

Both refuse to run outside an active transaction on the model's connection (`TransactionRequiredException`,
Spring's behaviour): a row lock is released when the transaction ends, so outside one it guards nothing. Refused
by the scanner on a `#[Query]` method — a raw statement carries its own locking clause. The clause is spelled by
the connection's own grammar (`FOR UPDATE`, `FOR SHARE`/`LOCK IN SHARE MODE`); sqlite has no row locks and
compiles it to nothing, so the read simply succeeds there.

### `#[EntityGraph]`

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/** @var array<string, list<string>> */
protected array $entityGraphs = ['Record.full' => ['entries']];
// …
/** @return list<Record> */
#[EntityGraph(attributePaths: ['entries'])]
public function findByStatusOrderByIdDesc(string $status): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<Record> $rows */
    return $rows;
}

/**
 * The inherited read under a NAMED graph — the Spring shape of annotating an overridden findAll().
 *
 * @return list<Record>
 */
#[EntityGraph('Record.full')]
public function findAll(): array
{
    return parent::findAll();
}
```

Mapped to Eloquent's `with()`. Every inherited read (`findById`, `findAll`, `findAllById`, `findPaged`,
`findSorted`, `findSlice`, `findBySpecification[Paged]`, `findByExample[Paged]`, `findOneByExample`,
`findAllIncludingDeleted`, `findByIdForUpdate`) starts from the graph the manifest holds for *this repository
class and that method*, so annotating an override that returns `parent::findAll()` is the whole recipe — the
Spring shape. A named graph the repository does not declare is a `ConfigurationException` at first use.

### Slices

A derived method whose **last argument is a `Pageable`** pages its result: a `Slice` when its declared return
type is `Slice`, a `Page` otherwise — so an undeclared `@method` derived query with a `Pageable` is always a
`Page` (it has no declared type to read).

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/**
 * A derived query that pages as a Slice: the trailing Pageable is what makes it page, the declared return
 * type is what makes it a Slice rather than a Page.
 *
 * @return Slice<Record>
 */
#[EntityGraph(attributePaths: ['entries'])]
public function findByStatusOrderByIdAsc(string $status, Pageable $pageable): Slice
{
    $slice = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert($slice instanceof Slice);

    /** @var Slice<Record> $slice */
    return $slice;
}
```

`$repo->findSlice(Pageable::of(3, 20, Sort::by('id')))` is the port's own count-free page.

## Pagination value objects

`Page<T>` — one page of results plus the grand total, built at the Eloquent edge from a sliced fetch plus a
count query:

<!-- source: packages/data/src/Repository/Page.php -->
```php
final readonly class Page
{
    // …
    public function __construct(
        public array $items,
        public int $total,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function totalPages(): int
    {
        return $this->size > 0 ? (int) ceil($this->total / $this->size) : 0;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function numberOfElements(): int
    {
        return count($this->items);
    }
    // …
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->total, $this->page, $this->size);
    }
}
```

`Slice<T>` — a page without a total, built by fetching one row past the page size; no count query ever runs
(Spring's `content`/`number` are `items`/`page` here, the names `Page` already uses):

<!-- source: packages/data/src/Repository/Slice.php -->
```php
final readonly class Slice
{
    // …
    public function __construct(
        public array $items,
        public bool $hasNext,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function hasNext(): bool
    {
        return $this->hasNext;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function numberOfElements(): int
    {
        return count($this->items);
    }

    public function nextPageable(): Pageable
    {
        return Pageable::of($this->page + 1, $this->size);
    }
    // …
}
```

`Pageable` — a page request (1-based page number, size, optional `Sort`):

<!-- source: packages/data/src/Repository/Pageable.php -->
```php
final readonly class Pageable
{
    public function __construct(
        public int $page = 1,
        public int $size = 20,
        public ?Sort $sort = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page number is 1-based and must be >= 1.');
        }

        if ($size < 1) {
            throw new InvalidArgumentException('Page size must be >= 1.');
        }
    }

    public static function of(int $page, int $size, ?Sort $sort = null): self
    {
        return new self($page, $size, $sort);
    }

    public static function unpaged(?Sort $sort = null): self
    {
        return new self(1, PHP_INT_MAX, $sort);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }

    public function next(): self
    {
        return new self($this->page + 1, $this->size, $this->sort);
    }

    public function previous(): self
    {
        return new self(max(1, $this->page - 1), $this->size, $this->sort);
    }

    public function isPaged(): bool
    {
        return $this->size !== PHP_INT_MAX;
    }
}
```

`Sort`/`Order`/`Direction` — an immutable, composable ordering:

<!-- source: packages/data/src/Repository/Sort.php -->
```php
final readonly class Sort
{
    // …
    public function __construct(public array $orders = []) {}

    public static function unsorted(): self
    {
        return new self([]);
    }

    public static function by(string ...$properties): self
    {
        return new self(array_map(
            static fn (string $property): Order => Order::asc($property),
            array_values($properties),
        ));
    }

    public function and(self $other): self
    {
        return new self([...$this->orders, ...$other->orders]);
    }
    // …
    public function isSorted(): bool
    {
        return $this->orders !== [];
    }
}
```

`ascending()` and `descending()` are the two combinators cut from the middle: each rewrites every order's
direction and returns a new `Sort`. An `Order` is a property plus a `Direction`, and the enum's backing value
*is* the `orderBy` direction string, so there is no translation table anywhere:

<!-- source: packages/data/src/Repository/Order.php -->
```php
final readonly class Order
{
    public function __construct(
        public string $property,
        public Direction $direction = Direction::Asc,
    ) {}

    public static function asc(string $property): self
    {
        return new self($property, Direction::Asc);
    }

    public static function desc(string $property): self
    {
        return new self($property, Direction::Desc);
    }

    public function isAscending(): bool
    {
        return $this->direction === Direction::Asc;
    }
}
```

<!-- source: packages/data/src/Repository/Direction.php -->
```php
enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
```

`Direction`'s backing value **is** the Eloquent `orderBy()` direction string, so the mapping at the Eloquent
edge is a bare `->orderBy($order->property, $order->direction->value)` with no translation table.

## Exception translation

Every `EloquentRepository` method, and `TransactionTemplate::execute()` (and so every `#[Transactional]`
method), throws the kernel's `DataAccessException` family instead of a raw `QueryException` — Spring's
`PersistenceExceptionTranslator`, on by default under `firefly.data.exception-translation.enabled`. The
translated type is the sentence: `DuplicateKeyException` (409 `DUPLICATE_KEY`, under
`DataIntegrityViolationException`), `CannotAcquireLockException` / `DeadlockLoserDataAccessException` (409),
`QueryTimeoutException` (504), `DataAccessResourceFailureException` (503, unreachable),
`TransientDataAccessResourceException` (503, retryable), `BadSqlGrammarException` (500), the generic
`DataAccessException` (500) for anything the tables do not know; the original is `previous`, the SQLSTATE rides
as the `sqlState` extension member, and the message never carries the statement. The tables — driver error
codes for sqlite/mysql/mariadb/sqlsrv, exact SQLSTATEs, SQLSTATE classes — live in one file,
`Firefly\Data\Exception\DriverErrorTable`, with one test row per table row. `getById($id)` throws
`EmptyResultDataAccessException` (404) where `findById()` returns null; `findOneByExample()` throws
`IncorrectResultSizeDataAccessException` for more than one match; the existing `OptimisticLockException` is now
a kernel `OptimisticLockingFailureException` (409). See [Error Handling](error-handling.md) for the whole table.

## Configuration (`firefly.data.*`, kebab-case)

| Key | Default | Effect |
|---|---|---|
| `firefly.data.exception-translation.enabled` | `true` | translate driver failures into the `DataAccessException` family at the repository and the template |
| `firefly.data.transaction.default-timeout` | `0` | seconds a `#[Transactional]` method may run when it names no `timeout:`; 0 = none — see [Transactions](transactional.md#timeouts) |
| `firefly.data.transaction.statement-timeout` | `true` | issue the driver-level statement timeout at transaction start (the wall-clock check is always on when a timeout is set) |
| `firefly.data.transactional-event-listeners.enabled` | `true` | register `#[TransactionalEventListener]` methods — see [Transactions](transactional.md#transactional-event-listeners) |

## Known-latent

- **Attributes need a declared method.** `#[Modifying]`, `#[Projection]`, `#[Lock]`, `#[EntityGraph]` and a
  `Slice` return live on methods with a `dispatchQuery(__FUNCTION__, func_get_args())` body; an undeclared
  `@method` derived query cannot carry them and pages as a `Page`.
- **`#[Query]` binds positionally.** Named `:placeholders` are rewritten to `?` in appearance order; a
  placeholder used twice needs the argument twice.
- **Interface projections are out** — PHP has no runtime proxy for an interface keyed by column name; declare
  the DTO class.
- **A `#[Projection]` and a trailing `Pageable` do not combine**: the projection returns the whole (unpaged)
  result. Page the entities, or add a `LIMIT` to the `#[Query]`.

---

## Browsing what a repository holds

`firefly/admin` ships a [data browser](data-browser.md) that discovers its resources from exactly these ports:
any bean whose scan-time interface list contains `CrudRepository` is browsable, and a repository that also
implements `PagingAndSortingRepository` is paged **in the database** rather than in PHP — which on a large table
is the difference between one page of rows and an out-of-memory.

It is **disabled by default** and does not follow `app.debug` or `firefly.admin.enabled`; writes need a second
key on top of that, and create is offered only for an Eloquent-backed resource — for a hand-written aggregate
the invariants live in its constructor, not in a column list. See [Data Browser](data-browser.md) for the
reasoning, and for the relations it walks between your entities.

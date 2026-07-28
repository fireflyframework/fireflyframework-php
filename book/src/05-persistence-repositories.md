<span class="eyebrow">Part II — Modeling & Persisting the Domain · Chapter 5</span>

# Persistence & the Repository Pattern {.chtitle}

By the end of this chapter you will know the two ports every LaraFly repository implements, how `EloquentRepository` gives you full CRUD for the price of one `$model` assignment, how a derived-query method name compiles into a real Eloquent query with no body of your own, the `#[Query]` escape hatch for anything a name cannot express cleanly, how `Specification`s compose reusable predicates, the `Page`/`Pageable`/`Sort` value objects that carry pagination end to end, and the domain-event bridge that ties a repository's `save()` call to Chapter 6's aggregate.

!!! note "New term: derived query"
    A **derived query** is a repository method with **no body** — just a name like `findByOwnerId` — whose SQL the framework compiles by parsing the method name itself, at the moment it is first called. You write the name; the framework writes the `WHERE` clause.

---

## The ports: `CrudRepository` and `PagingAndSortingRepository`

Every LaraFly repository ultimately implements two small interfaces from `firefly/data`. PHP has no runtime generics, so the port signatures are deliberately `object`/`mixed`/`array` — the `@template` docblock parameters give PHPStan the precise entity and id types back:

```php
/**
 * @template TEntity of object
 * @template TId
 */
interface CrudRepository
{
    /**
     * @param  TEntity  $entity
     * @return TEntity
     */
    public function save(object $entity): object;

    /**
     * @param  iterable<TEntity>  $entities
     * @return list<TEntity>
     */
    public function saveAll(iterable $entities): array;

    /**
     * @param  TId  $id
     * @return TEntity|null
     */
    public function findById(mixed $id): ?object;

    /**
     * @return list<TEntity>
     */
    public function findAll(): array;

    /**
     * @param  iterable<TId>  $ids
     * @return list<TEntity>
     */
    public function findAllById(iterable $ids): array;

    /**
     * @param  TId  $id
     */
    public function existsById(mixed $id): bool;

    public function count(): int;

    /**
     * @param  TEntity  $entity
     */
    public function delete(object $entity): void;

    /**
     * @param  TId  $id
     */
    public function deleteById(mixed $id): void;

    public function deleteAll(): void;
}
```

Spring Data overloads a single `findAll(Pageable)`/`findAll(Sort)`; PHP has no method overloading, so `PagingAndSortingRepository` splits the two into distinct, cleanly-typed methods instead:

```php
/**
 * @template TEntity of object
 * @template TId
 *
 * @template-extends CrudRepository<TEntity, TId>
 */
interface PagingAndSortingRepository extends CrudRepository
{
    /**
     * @return Page<TEntity>
     */
    public function findPaged(Pageable $pageable): Page;

    /**
     * @return list<TEntity>
     */
    public function findSorted(Sort $sort): array;
}
```

You will not implement either interface directly. `Firefly\Data\Repository\EloquentRepository` already implements both over `Model::query()`, and every concrete repository in your application extends *that* — the same relationship Chapter 2 described between an interface and its sole implementing bean, now one level lower in the stack.

!!! laravel "Laravel parity"
    `CrudRepository`/`PagingAndSortingRepository` are LaraFly's counterparts to Spring Data's `CrudRepository`/`PagingAndSortingRepository`. If you have written a Spring `interface OrderRepository extends JpaRepository<Order, UUID>`, `EloquentRepository` is the same idea translated onto Eloquent: you get the full CRUD surface from a single `extends`, and add only the queries specific to your entity.

---

## The port and the adapter: `WalletRepository`

Chapter 2 introduced the hexagonal split between a *port* your domain and application layers depend on, and the *adapter* that actually talks to storage. Persistence is where that split earns its keep. Here is the whole port, unchanged from Chapter 2:

```php
<?php

declare(strict_types=1);

namespace Lumen\Infrastructure;

use Lumen\Domain\Wallet;

/**
 * The hexagonal PORT for wallet persistence: the domain/application layer depends on this interface only, never on
 * Eloquent or any storage detail. `EloquentWalletRepository` is the sole adapter, auto-bound by the framework's
 * nominal interface auto-binding (Firefly\Container's ComponentScanner/ContainerRegistrar::wireInterfaces()).
 */
interface WalletRepository
{
    public function save(Wallet $wallet): Wallet;

    public function findById(string $id): ?Wallet;

    /** @return list<Wallet> */
    public function findByOwnerId(string $ownerId): array;
}
```

And here is the adapter — the class that extends `EloquentRepository` and does the real work:

```php
<?php

declare(strict_types=1);

namespace Lumen\Infrastructure;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;
use InvalidArgumentException;
use Lumen\Domain\Wallet;

/**
 * The Eloquent ADAPTER for the `WalletRepository` port, bound to the `Wallet` aggregate model.
 *
 * Because this class `implements WalletRepository` (a real PHP interface) in addition to `extends
 * EloquentRepository`, every interface method needs an EXPLICIT body — the framework's own convention of leaning on
 * `EloquentRepository::__call()` (magic dispatch, documented via `@method` docblocks only) does NOT satisfy an
 * `implements` contract; PHP fatals at class-load time if an abstract interface method is left undefined.
 *
 * `save()`/`findById()` keep the PARENT's exact parameter type (`object`/`mixed`) and only narrow the RETURN type to
 * `Wallet` — not the parameter to `Wallet`, even though that is what `WalletRepository` declares. This is a real PHP
 * variance constraint, not a stylistic choice: overriding `EloquentRepository::save(object $entity): object` with a
 * narrower parameter (`Wallet $wallet`) is an invalid override (contravariance requires the override's parameter to
 * be the same type or WIDER than the parent's, never narrower) and fatals at class-load — confirmed empirically
 * while building this adapter. Widening the implementation's parameter back to `object`/`mixed` still satisfies
 * `WalletRepository`'s narrower `Wallet`/`string` parameters, because interface conformance uses the same
 * contravariant-parameter/covariant-return rule: an implementation may accept MORE than the interface promises and
 * return exactly what it promises. An `instanceof` guard narrows the parameter back to `Wallet` before delegating to
 * `parent::save()`, so the generic template parameter on the parent resolves to `Wallet` and the return type lines
 * up with no extra type-override annotation or PHPStan suppression comment needed.
 *
 * `findByOwnerId()` — a derived query with no parent counterpart, so no variance constraint applies — delegates
 * explicitly to the protected `dispatchQuery()` dispatcher (the same engine `__call()` would have reached, just
 * invoked directly so the method has a real body, per the `implements` requirement above).
 *
 * @extends EloquentRepository<Wallet>
 */
#[Repository]
final class EloquentWalletRepository extends EloquentRepository implements WalletRepository
{
    protected string $model = Wallet::class;

    public function save(object $entity): Wallet
    {
        if (! $entity instanceof Wallet) {
            throw new InvalidArgumentException(sprintf('%s::save() only accepts a %s.', self::class, Wallet::class));
        }

        return parent::save($entity);
    }

    public function findById(mixed $id): ?Wallet
    {
        $found = parent::findById($id);

        return $found instanceof Wallet ? $found : null;
    }

    /** @return list<Wallet> */
    public function findByOwnerId(string $ownerId): array
    {
        /** @var list<Wallet> $result */
        $result = $this->dispatchQuery('findByOwnerId', [$ownerId]);

        return $result;
    }
}
```

Notice the `protected string $model = Wallet::class;` line — that single assignment is what tells `EloquentRepository` which model `query()` should run against, and it is the entire reason the full CRUD surface (`save`, `saveAll`, `findById`, `findAll`, `findAllById`, `existsById`, `count`, `delete`, `deleteById`, `deleteAll`, plus paging and sorting) shows up on `EloquentWalletRepository` for free. `$this->model::query()` is the one line at the bottom of every inherited method.

!!! warning "A PHP variance constraint, not a design choice"
    A class that both `extends EloquentRepository` and `implements WalletRepository` must give every interface method an explicit body — magic `__call()` dispatch alone does not satisfy an `implements` clause. And because `WalletRepository::save(Wallet $wallet): Wallet` narrows the parent's `save(object $entity): object`, overriding with the *narrower* parameter type is an invalid override in PHP (parameter types must stay the same or widen, never narrow); `EloquentWalletRepository::save()` keeps the parent's `object $entity` signature and narrows only the *return* type, guarding the actual `Wallet` requirement with an `instanceof` check inside the method body instead.

---

## Derived queries: the method name is the query

`findByOwnerId` above has no query logic in its own body — `dispatchQuery()` is the shared engine underneath both it and plain `__call()` magic dispatch. A repository method whose name follows the grammar below needs no body at all when called through `__call()`; `EloquentRepository::dispatchQuery()` parses the method name with `DerivedQueryParser::parse()` — pure string parsing, no reflection, the method name is the only input — and drives an Eloquent `Builder` accordingly.

### The grammar

| Piece | Values | Notes |
|---|---|---|
| Prefix | `findBy` / `countBy` / `existsBy` / `deleteBy` | `find` also accepts `findFirstBy…` (limit 1), `findTop{N}By…` (limit N), `findDistinctBy…` (adds `DISTINCT`) before the `By`. |
| Connector | `And` / `Or` | Splits predicate groups; only recognized before a new CamelCase field. |
| Operator | see below | Matched **longest-first** against the end of each predicate group; `Equals` (no token) is the implicit default. |
| Case modifier | `IgnoreCase` suffix | Routes the equality/LIKE family through `LOWER(col) op LOWER(?)`. |
| Ordering | trailing `OrderBy{Field}{Asc\|Desc}` | Chainable; direction defaults to `asc` if omitted. |

Operators, in the exact longest-match-first order the parser walks:

| Operator | SQL effect | Args consumed |
|---|---|---|
| `GreaterThanEqual` | `>=` | 1 |
| `LessThanEqual` | `<=` | 1 |
| `GreaterThan` | `>` | 1 |
| `LessThan` | `<` | 1 |
| `Between` | `BETWEEN ? AND ?` | 2 |
| `NotLike` | `not like` | 1 |
| `Like` | `like` | 1 |
| `NotIn` | `NOT IN (?)` | 1 (array) |
| `In` | `IN (?)` | 1 (array) |
| `Containing` | `like '%value%'` | 1 |
| `StartingWith` | `like 'value%'` | 1 |
| `EndingWith` | `like '%value'` | 1 |
| `IsNotNull` | `IS NOT NULL` | 0 |
| `IsNull` | `IS NULL` | 0 |
| `Not` | `!=` | 1 |
| `True` | `= true` | 0 |
| `False` | `= false` | 0 |
| *(none)* | `Equals` → `=` | 1 |

Each CamelCase field segment maps to a `snake_case` column, and every derived column is validated against a bare-identifier pattern before it can reach a query — the method name is untrusted input reachable through the public `__call`, so this validation is what keeps the `IgnoreCase` path's raw `LOWER(col)` fragment injection-free. Arguments bind to predicates in declaration order, left to right, advancing a cursor by however many values each operator consumes.

A hypothetical `RecordRepository` shows the grammar at a glance — the `@method` tags are how PHPStan sees a typed return for what is, at runtime, dynamic `__call` dispatch:

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
```

`EloquentWalletRepository::findByOwnerId()` is the real, shipped version of exactly this pattern — the only difference is that it `implements WalletRepository`, so its body calls `dispatchQuery()` explicitly instead of relying on `__call()`, for the variance reason explained above. Either way, `findByOwnerId('alice')` compiles to `WHERE owner_id = ?` with no SQL written by hand.

!!! tip "When a name would get silly, use `#[Query]`"
    Derived names read well up to two or three predicates. Past that, reach for the explicit-query escape hatch below instead of a fifty-character method name.

---

## The `#[Query]` explicit-query escape hatch

A method can declare its SQL directly, bypassing the derived-query grammar entirely:

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
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;

    #[Query('select * from records where email = :email order by amount asc')]
    public function findByEmailRaw(string $email): array
    {
        return $this->dispatchQuery(__FUNCTION__, func_get_args());
    }
}
```

Named `:param` placeholders rewrite to positional `?` in appearance order, and the method's own arguments bind positionally. `#[Query]` methods are discovered by the same `TransactionalScanner` that compiles the `#[Transactional]` manifest (the next chapter's section on transactions covers that scanner in full), so a declared method's SQL resolves from the compiled manifest with zero per-request reflection — both the derived-query path and an explicit `#[Query]` method funnel through the single `dispatchQuery()` method shared by `__call()` and any method body that calls it directly.

---

## `Specification`: composable, reusable predicates

Derived queries answer fixed questions. A **`Specification`** is a reusable predicate you can name once and compose freely at the call site — "wallets with at least this balance," combined with other conditions as needed:

```php
/** @template TModel of Model */
interface Specification
{
    public function toBuilder(Builder $query): Builder;
}
```

`Specifications` is the static factory — PHP interfaces cannot carry static factory bodies:

```php
Specifications::allOf(...$specifications); // AND-folds left-to-right; zero-arg = match-all
Specifications::anyOf(...$specifications); // OR-folds left-to-right; zero-arg = match-all
Specifications::not($specification);
Specifications::where(fn (Builder $q) => $q->where('status', 'open'));
```

Applying a built specification runs through two repository methods every `EloquentRepository` already provides:

```php
abstract class EloquentRepository implements PagingAndSortingRepository
{
    /**
     * @param  Specification<Model>  $specification
     * @return list<TModel>
     */
    public function findBySpecification(Specification $specification): array
    {
        return $this->narrow($specification->toBuilder($this->query())->get()->all());
    }

    /**
     * @param  Specification<Model>  $specification
     * @return Page<TModel>
     */
    public function findBySpecificationPaged(Specification $specification, Pageable $pageable): Page
    {
        $total = $specification->toBuilder($this->query())->count();

        $items = $this->applySort($specification->toBuilder($this->query()), $pageable->sort)
            ->skip($pageable->offset())
            ->take($pageable->size)
            ->get()
            ->all();

        return new Page($this->narrow($items), $total, $pageable->page, $pageable->size);
    }
}
```

A specification for wallets above a minimum balance, composed with a currency filter, reads exactly like the rule it expresses:

```php
$rich = Specifications::where(
    fn (Builder $q) => $q->where('balance_minor', '>=', 100_000)
);
$inEur = Specifications::where(fn (Builder $q) => $q->where('currency', 'EUR'));

$richEurWallets = Specifications::allOf($rich, $inEur);
```

`$repo->findBySpecificationPaged($richEurWallets, Pageable::of(1, 20))` runs that composite predicate, counts the matches, sorts, and slices — returning a `Page` with no SQL of your own beyond the two `where()` closures.

---

## Pagination: `Page`, `Pageable`, and `Sort`

Three small, immutable value objects carry a page request in and a page result out, end to end. `Pageable` is the request — a 1-based page number, a size, and an optional `Sort`:

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
}
```

`Sort` and `Order` compose an ordering out of small, immutable pieces — every combinator returns a *new* `Sort`, never mutates the one you started with:

```php
final readonly class Sort
{
    /**
     * @param  list<Order>  $orders
     */
    public function __construct(public array $orders = []) {}

    public static function by(string ...$properties): self
    {
        return new self(array_map(
            static fn (string $property): Order => Order::asc($property),
            array_values($properties),
        ));
    }

    public function descending(): self
    {
        return new self(array_map(
            static fn (Order $order): Order => Order::desc($order->property),
            $this->orders,
        ));
    }
}
```

`Order::asc('created_at')`/`Order::desc('created_at')` pair a property name with a `Direction::Asc`/`Direction::Desc` enum whose backing *string value* **is** the Eloquent `orderBy()` direction — `$order->direction->value` needs no translation table at all.

`Page` is what comes back out — the row slice plus everything a client needs to render a pager:

```php
/**
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
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

    /**
     * @template U
     *
     * @param  callable(T): U  $mapper
     * @return self<U>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->total, $this->page, $this->size);
    }
}
```

`EloquentRepository::findPaged()` builds a `Page` at the Eloquent edge: a `count()` query for `total`, then `skip($pageable->offset())->take($pageable->size)` for the slice, with `applySort()` folding each `Sort` order into an `orderBy()` call in between. `Page::map()` is what a query handler reaches for to turn a page of entities into a page of DTOs **without losing the pagination metadata** — the same `total`/`page`/`size` numbers survive the transformation untouched.

---

## Soft delete, auditing, and optimistic locking

Three more building blocks live on `EloquentRepository`, layered on Eloquent's own mechanisms rather than reinvented. None of them is exercised by `Wallet` in this book, but each is one `use` statement away on any model that needs it.

**Soft delete** reuses Eloquent's native `SoftDeletes` trait as-is:

```php
final class SoftRecord extends Model
{
    use SoftDeletes;
}
```

Once a model uses it, `delete()`/`deleteById()` through the repository soft-deletes the row, and every ordinary read transparently excludes trashed rows via Eloquent's own global scope. `findAllIncludingDeleted()` and `restore(mixed $id): ?object` round out the lifecycle.

**Auditing** is an opt-in `Auditable` trait that stamps `created_by`/`updated_by` on write, driven by an `AuditorAware` port:

```php
interface AuditorAware
{
    public function currentAuditor(): int|string|null;
}
```

**Optimistic locking** guards concurrent writes with a `version` integer column via `HasOptimisticLock`, which overrides Eloquent's internal `performUpdate()` to add `WHERE version = <loaded version>` to every `UPDATE` — a write against a stale version matches zero rows and throws `OptimisticLockException` instead of silently overwriting someone else's change.

---

## The domain-event bridge

`EloquentRepository::save()` does one thing beyond persisting the row that matters enormously for Chapter 6: if the saved entity also implements `RecordsDomainEvents` — which `Wallet` does — **and** a transaction is currently active on that entity's own connection, `save()` registers the entity with `AggregateTracker`, `firefly/data`'s unit-of-work registry:

```php
abstract class EloquentRepository implements PagingAndSortingRepository
{
    public function save(object $entity): object
    {
        if ($entity instanceof Model) {
            $entity->save();
        }

        if ($entity instanceof RecordsDomainEvents
            && $this->tracker !== null
            && $this->connectionFor($entity)->transactionLevel() > 0) {
            $this->tracker->track($entity);
        }

        return $entity;
    }
}
```

That registration is what lets the framework drain and publish a `Wallet`'s raised domain events after the surrounding `#[Transactional]` method commits — and never if it rolls back. Chapter 6 builds the aggregate that raises those events; this is the seam, on the persistence side, that catches them.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `CrudRepository` / `PagingAndSortingRepository` | The persistence-agnostic ports every repository ultimately implements |
| `EloquentRepository` | The Eloquent-backed base: set `$model`, inherit full CRUD + paging + sorting |
| Derived query (`findByOwnerId`) | A bodiless method name parsed by `DerivedQueryParser` and driven against the Eloquent `Builder` |
| `#[Query('sql')]` | An explicit-SQL escape hatch, discovered at scan time, resolved with zero per-request reflection |
| `Specification` / `Specifications` | Composable predicates (`allOf`/`anyOf`/`not`/`where`) applied via `findBySpecification(Paged)` |
| `Page` / `Pageable` / `Sort` / `Order` | Immutable value objects carrying a page request in and a page result (with metadata) out |
| `SoftDeletes` / `Auditable` / `HasOptimisticLock` | Opt-in traits for soft-delete, `created_by`/`updated_by` stamping, and version-guarded writes |
| The domain-event bridge | `save()` registers a `RecordsDomainEvents` entity with `AggregateTracker` while a transaction is active |

---

## Try it yourself {.exercises}

1. **Add a derived counter.** In a scratch copy of the project, declare `public function countByCurrency(string $currency): int` with no body on a repository extending `EloquentRepository`, and confirm calling it compiles to `SELECT COUNT(*) … WHERE currency = ?` with no SQL of your own.
2. **Compose two specifications.** Build a `Specification` for "balance at least N" and another for "in currency C" with `Specifications::where(...)`, combine them with `Specifications::allOf(...)`, and run the composite through `findBySpecificationPaged` against a small seeded table.
3. **Read a page of wallets.** Call `EloquentWalletRepository::findAll(...)`'s paging counterpart (add a `findPaged` caller through `WalletRepository` in your scratch project) with `Pageable::of(1, 2, Sort::by('created_at')->descending())` against three seeded wallets, and confirm `total`, `totalPages`, and `hasNext` all match what you expect before and after `Page::map()`.

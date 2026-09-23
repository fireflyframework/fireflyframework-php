<span class="eyebrow">Part II — Modeling & Persisting the Domain · Chapter 5</span>

# Persistence & the Repository Pattern {.chtitle}

By the end of this chapter you will know the two ports every LaraFly repository implements, how `EloquentRepository` gives you full CRUD for the price of one `$model` assignment, how a derived-query method name compiles into a real Eloquent query with no body of your own, the `#[Query]` escape hatch for anything a name cannot express cleanly, how `Specification`s compose reusable predicates, how **query by example** turns a half-filled entity into a query with no predicate written at all, what the four method attributes (`#[Modifying]`, `#[Projection]`, `#[Lock]`, `#[EntityGraph]`) each say about a method, the `Page`/`Pageable`/`Sort` value objects that carry pagination end to end and the `Slice` that deliberately does not count, the `DataAccessException` family every driver error is translated into — and where that translation deliberately does not happen — and the domain-event bridge that ties a repository's `save()` call to Chapter 6's aggregate.

!!! note "New term: derived query"
    A **derived query** is a repository method with **no body** — just a name like `findByOwnerId` — whose SQL the framework compiles by parsing the method name itself, at the moment it is first called. You write the name; the framework writes the `WHERE` clause.

---

## The ports: `CrudRepository` and `PagingAndSortingRepository`

Every LaraFly repository ultimately implements two small interfaces from `firefly/data`. PHP has no runtime generics, so the port signatures are deliberately `object`/`mixed`/`array` — the `@template` docblock parameters give PHPStan the precise entity and id types back:

<!-- source: packages/data/src/Repository/CrudRepository.php -->
```php
/**
 // …
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

<!-- source: packages/data/src/Repository/PagingAndSortingRepository.php -->
```php
/**
 // …
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
     // …
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

<!-- source: samples/lumen/src/Infrastructure/WalletRepository.php -->
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

<!-- source: samples/lumen/src/Infrastructure/EloquentWalletRepository.php -->
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

The framework's own `RecordRepository` fixture — the one `packages/data`'s derived-query suite runs against — shows the grammar at a glance. The `@method` tags are how PHPStan sees a typed return for what is, at runtime, dynamic `__call` dispatch:

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/**
 // …
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
```

Not one of those six methods is declared anywhere in the class: `__call()` parses each name the first time it is used and the compiled manifest answers from then on.

`EloquentWalletRepository::findByOwnerId()` is the real, shipped version of exactly this pattern — the only difference is that it `implements WalletRepository`, so its body calls `dispatchQuery()` explicitly instead of relying on `__call()`, for the variance reason explained above. Either way, `findByOwnerId('alice')` compiles to `WHERE owner_id = ?` with no SQL written by hand.

!!! tip "When a name would get silly, use `#[Query]`"
    Derived names read well up to two or three predicates. Past that, reach for the explicit-query escape hatch below instead of a fifty-character method name.

---

## The `#[Query]` explicit-query escape hatch

A method can declare its SQL directly, bypassing the derived-query grammar entirely:

<!-- source: packages/data/src/Repository/Attributes/Query.php -->
```php
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
/**
 // …
 * @return list<array<string, mixed>>
 */
#[Query('select * from records where email = :email order by amount asc')]
public function findByEmailRaw(string $email): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<array<string, mixed>> $rows */
    return $rows;
}
```

The body is the same one line every declared repository method carries: hand the method's own name and its arguments to the shared dispatcher, which finds the method in the manifest's `#[Query]` set and runs the SQL. The `assert()` and the `@var` are there for PHPStan, which cannot see through `mixed`.

Named `:param` placeholders rewrite to positional `?` in appearance order, and the method's own arguments bind positionally. `#[Query]` methods are discovered by the same `TransactionalScanner` that compiles the `#[Transactional]` manifest (the next chapter's section on transactions covers that scanner in full), so a declared method's SQL resolves from the compiled manifest with zero per-request reflection — both the derived-query path and an explicit `#[Query]` method funnel through the single `dispatchQuery()` method shared by `__call()` and any method body that calls it directly.

---

## `Specification`: composable, reusable predicates

Derived queries answer fixed questions. A **`Specification`** is a reusable predicate you can name once and compose freely at the call site — "wallets with at least this balance," combined with other conditions as needed:

<!-- source: packages/data/src/Repository/Specification/Specification.php -->
```php
interface Specification
{
    // …
    public function toBuilder(Builder $query): Builder;
}
```

`Specifications` is the static factory — PHP interfaces cannot carry static factory bodies:

<!-- illustrative: the four factory calls a reader makes from their own code -->
```php
Specifications::allOf(...$specifications); // AND-folds left-to-right; zero-arg = match-all
Specifications::anyOf(...$specifications); // OR-folds left-to-right; zero-arg = match-all
Specifications::not($specification);
Specifications::where(fn (Builder $q) => $q->where('status', 'open'));
```

Applying a built specification runs through two repository methods every `EloquentRepository` already provides:

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

Four helpers carry everything those two bodies do not spell out, and the split in their visibility is the
point. `reading()` and `translating()` are **`protected`** — they are the seam a subclass repository reaches
for, which is what makes the "annotate an override" recipe below available to you at all — while `pageOf()`
and `narrow()` are `private` plumbing. `reading(__FUNCTION__)` opens the query and applies whatever
`#[EntityGraph]` the compiled manifest holds *for this repository class and this method*, which is why
annotating an override is the whole recipe. `narrow()` is the one the paged body above does not call: it keeps
only the rows that really are `TModel` and hands back a `list<TModel>`. `pageOf()` counts over a clone
of the builder and then windows it, so the count and the slice see the same predicate. And `translating()`
wraps the terminal call so a driver failure leaves the repository as a typed `DataAccessException` rather
than a raw `QueryException` — the subject of this chapter's last section.

A specification for wallets above a minimum balance, composed with a currency filter, reads exactly like the rule it expresses:

<!-- illustrative: two specifications an application composes out of its own predicates -->
```php
$rich = Specifications::where(
    fn (Builder $q) => $q->where('balance_minor', '>=', 100_000)
);
$inEur = Specifications::where(fn (Builder $q) => $q->where('currency', 'EUR'));

$richEurWallets = Specifications::allOf($rich, $inEur);
```

`$repo->findBySpecificationPaged($richEurWallets, Pageable::of(1, 20))` runs that composite predicate, counts the matches, sorts, and slices — returning a `Page` with no SQL of your own beyond the two `where()` closures.

---

## Query by example

Sometimes the predicate you want is simply *"another row that looks like this one."* Spring Data calls that an `Example`, and `firefly/data` ports it in the way that costs nothing to learn: **an `Example` is a `Specification`.** It has no separate call path, no special repository method it can only reach through, no rules of composition of its own. Everything the previous section showed applies to it unchanged.

<!-- source: packages/data/src/Repository/Example/Example.php -->
```php
final readonly class Example implements Specification
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    // …
    public static function of(object|array $probe, ?ExampleMatcher $matcher = null): self
    {
        $attributes = match (true) {
            $probe instanceof Model => [...$probe->getRawOriginal(), ...$probe->getDirty()],
            is_object($probe) => get_object_vars($probe),
            default => $probe,
        };
        // …
        return new self($validated, $matcher ?? ExampleMatcher::matching());
    }
```

The **probe** is the thing that looks like what you want. It can be an Eloquent model with some attributes set, any plain object (its public properties), or a bare `column => value` array. A model probe contributes exactly the attributes that were *set* — `new Record(['status' => 'open'])` is a one-property probe, not a probe of every column in the table — because the attributes are read as the model's raw originals overlaid with its dirty set.

Every probe key is validated as a bare identifier right there in `of()`, and an invalid one is an `InvalidArgumentException` before any query is built. That is not defensive decoration: a key reaches a raw SQL fragment when case is ignored or a `LIKE` needs its `ESCAPE` clause, so it is checked at the only place where checking it once is enough — the same discipline the derived-query parser applies to field names.

Because an `Example` is a `Specification`, the repository methods you already know take it directly, and there are four convenience methods named the way a Spring Data reader expects:

<!-- source: packages/data/tests/Repository/Example/QueryByExampleTest.php -->
```php
$repo = new RecordRepository;

expect($repo->findByExample(Example::of(['status' => 'open', 'amount' => 150])))->toHaveCount(1)
    ->and($repo->findByExample(Example::of(new Record(['status' => 'closed']))))->toHaveCount(3)
    ->and($repo->countByExample(Example::of(['status' => 'open'])))->toBe(3)
    ->and($repo->existsByExample(Example::of(['status' => 'archived'])))->toBeFalse()
    ->and($repo->findByExample(Example::of([])))->toHaveCount(6);
```

An empty probe matches everything, which is the honest answer rather than an error: no properties means no predicate. `findOneByExample()` is the at-most-one variant — `null` for none, the row for one, and `IncorrectResultSizeDataAccessException` for more than one, because an ambiguous probe is a programming error and not a result:

<!-- source: packages/data/tests/Repository/Example/QueryByExampleTest.php -->
```php
expect($repo->findOneByExample(Example::of(['amount' => 150]))?->email)->toBe('b@x.test')
    ->and($repo->findOneByExample(Example::of(['amount' => 999])))->toBeNull()
    ->and(fn () => $repo->findOneByExample(Example::of(['status' => 'open'])))->toThrow(IncorrectResultSizeDataAccessException::class);
```

### The matcher carries the rules

A probe on its own says *what* to compare. An `ExampleMatcher` says *how*. It is an immutable value object whose every `with*` returns a new instance, so a matcher is safe to build once and share:

<!-- source: packages/data/src/Repository/Example/ExampleMatcher.php -->
```php
public function withIgnorePaths(string ...$paths): self
{
    return $this->copy(ignoredPaths: array_values(array_unique([...$this->ignoredPaths, ...$paths])));
}

/** No paths: every string property ignores case. With paths: only those. */
public function withIgnoreCase(string ...$paths): self
{
    return $paths === []
        ? $this->copy(ignoreCaseAll: true)
        : $this->copy(ignoreCasePaths: array_values(array_unique([...$this->ignoreCasePaths, ...$paths])));
}

public function withIncludeNullValues(): self
{
    return $this->copy(includeNullValues: true);
}

public function withStringMatcher(StringMatcher $matcher): self
{
    return $this->copy(defaultStringMatcher: $matcher);
}

public function withMatcher(string $path, GenericPropertyMatcher $matcher): self
{
    return $this->copy(propertyMatchers: [...$this->propertyMatchers, $path => $matcher]);
}
```

The rules those five combinators express, in full:

| Rule | Default | How to change it |
|---|---|---|
| How properties fold together | `AND` (`matching()` = `matchingAll()`) | `ExampleMatcher::matchingAny()` for `OR` |
| A `null` probe value | skipped entirely | `withIncludeNullValues()` makes it `IS NULL` |
| A property you do not want compared | compared | `withIgnorePaths('id', 'created_at')` |
| How a **string** value is compared | `StringMatcher::EXACT` (`=`) | `withStringMatcher(StringMatcher::CONTAINING)` and friends |
| Case sensitivity | case-sensitive | `withIgnoreCase()` for every string, `withIgnoreCase('email')` for one |
| One property that needs its own rules | follows the defaults above | `withMatcher('email', GenericPropertyMatcher::startsWith()->caseSensitive())` |

Only **string** values get string matching. An `int`, a `float` or a `bool` is always compared with `=`, whatever the default string matcher says — a `CONTAINING` matcher does not quietly turn `amount => 5` into `LIKE '%5%'`.

A per-path matcher beats the matcher-wide settings for that path and leaves every other path alone:

<!-- source: packages/data/tests/Repository/Example/ExampleMatcherTest.php -->
```php
$matcher = ExampleMatcher::matching()
    ->withIgnoreCase()
    ->withMatcher('email', GenericPropertyMatcher::startsWith()->caseSensitive());

expect($matcher->isIgnoreCase('status'))->toBeTrue()
    ->and($matcher->isIgnoreCase('email'))->toBeFalse()
    ->and($matcher->stringMatcherFor('email'))->toBe(StringMatcher::STARTING)
    ->and($matcher->stringMatcherFor('status'))->toBe(StringMatcher::EXACT)
```

`StringMatcher` has four cases — `EXACT`, `CONTAINING`, `STARTING`, `ENDING` — and the three `LIKE` ones escape the value before it goes anywhere near a pattern:

<!-- source: packages/data/src/Repository/Example/StringMatcher.php -->
```php
public function pattern(string $value): string
{
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

    return match ($this) {
        self::EXACT => $escaped,
        self::CONTAINING => '%'.$escaped.'%',
        self::STARTING => $escaped.'%',
        self::ENDING => '%'.$escaped,
    };
}
```

So a probe of `under_` searches for a literal underscore, not for "any character". The fragment `Example` emits carries `ESCAPE '!'` — the one escape spelling that reads the same on sqlite, mysql, pgsql and sqlsrv, which a backslash does not (mysql consumes it inside the string first).

Spring's `REGEX` matcher is deliberately absent: there is no portable SQL for it.

### It composes, because it is a specification

The point of porting `Example` *as* a `Specification` rather than beside one is this — an example can be one leaf of a larger predicate, and it pages exactly like any other:

<!-- source: packages/data/tests/Repository/Example/QueryByExampleTest.php -->
```php
$page = $repo->findByExamplePaged(Example::of(['status' => 'open']), Pageable::of(1, 2, Sort::by('amount')->descending()));

expect($page->total)->toBe(3)
    ->and($page->items)->toHaveCount(2)
    ->and($page->items[0]->amount)->toBe(150);

$combined = Specifications::allOf(
    Example::of(['status' => 'closed']),
    Specifications::where(static fn (Builder $q): Builder => $q->where('amount', '>', 100)),
);

expect($repo->findBySpecification($combined))->toHaveCount(1);
```

!!! tip "Where query by example earns its keep"
    An admin search form with eight optional fields is the canonical case. Build the probe from whatever the user filled in, ignore the rest, and you have written no conditional query-building code at all — the empty fields simply are not in the probe.

---

## Saying more about a method: `#[Modifying]`, `#[Projection]`, `#[Lock]`, `#[EntityGraph]`

Four attributes let a repository method say something the method *name* cannot. Before any of them, one rule that catches every reader exactly once:

!!! warning "These attributes need a **declared** method"
    PHP cannot attach an attribute to a method that is never declared, and a derived query reached through `__call()` is never declared — it exists only as an `@method` docblock tag. So a method carrying one of these four attributes has a real body, and that body is always the same one line: `return $this->dispatchQuery(__FUNCTION__, func_get_args());` (plus whatever `assert()` PHPStan needs). Declaring the method changes nothing about how it resolves; it only gives the attribute somewhere to live.

The scanner records all four into the compiled manifest at build time, and `EloquentRepository` honours them at dispatch with **no reflection at request time** — a `#[Projection]`'s DTO constructor, for instance, is reflected exactly once, when `firefly:cache` compiles the manifest.

### `#[Modifying]` — this method writes

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

The SQL runs as a statement rather than a query, and the method returns the affected-row count. Two things are refused at *scan* time, so you hear about them from `firefly:cache` or the first boot and never from a user's request: `#[Modifying]` without a `#[Query]` (a derived `deleteBy…` is already a statement and needs no attribute), and a `#[Query]` whose SQL is a `SELECT`, `WITH` or `VALUES`. At run time the statement refuses to execute outside an active transaction on the model's connection — a `TransactionRequiredException` — unless you opt out with `requiresTransaction: false`.

Spring's `clearAutomatically` is accepted for source compatibility and does nothing. Eloquent has no persistence context to clear, and pretending otherwise would be the worst kind of parity.

### `#[Projection]` — select less, hydrate a DTO

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

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
#[Projection(RecordSummary::class)]
public function findByStatusOrderByAmountAsc(string $status): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<RecordSummary> $rows */
    return $rows;
}
```

On a **derived** method the `SELECT` list is inferred from the DTO's constructor parameters (or given explicitly with `columns:`); on a `#[Query]` method the SQL owns its own select list. Each row is hydrated through the constructor: snake_case column to camelCase parameter, scalars coerced from whatever the driver returned, a backed enum from its backing value, a `DateTimeImmutable` from the string, and an optional parameter whose column is absent takes its default.

Coercion is **lossless or refused**. `'150.75'` into an `int`, a backing value the enum has no case for, a string that is not a date — each is a `ConfigurationException` naming the DTO, the column, the value *and* the parameter, never a bare `TypeError` from somewhere deep in PHP. A missing required column and a `NULL` arriving at a non-nullable parameter are the same kind of error, and the columns are checked against the table before the query runs (sqlite would otherwise read an unknown double-quoted identifier as a string literal and hand back nonsense).

Spring's interface projections (`interface RecordSummary { String getEmail(); }`) are not offered. PHP has no runtime proxy that could implement an interface by column name; declare the DTO class instead.

### `#[Lock]` — take the row lock the read needs

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
```

`PESSIMISTIC_WRITE` compiles to `lockForUpdate()`, `PESSIMISTIC_READ` to `sharedLock()`, spelled by the connection's own grammar. Both refuse to run outside an active transaction — Spring's behaviour, and the right one: a row lock is released when the transaction ends, so outside one it guards nothing. `$repo->findByIdForUpdate($id)` is the programmatic twin of the attribute, and sqlite, which has no row locks at all, compiles the clause to nothing and simply succeeds.

The scanner refuses `#[Lock]` on a `#[Query]` method: raw SQL carries its own locking clause, and two of them would fight.

### `#[EntityGraph]` — eager-load, declaratively

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
```

It maps to Eloquent's `with()`, and it is the one attribute that reaches methods you did not write. Every inherited read that **opens a builder** — `findById`, `findAll`, `findAllById`, `findPaged`, `findSorted`, `findSlice`, `findBySpecification`, `findByExample`, `findOneByExample`, `findByIdForUpdate` and the rest — starts from `reading(__FUNCTION__)`, which asks the manifest for the graph registered against *this repository class and that method name*. So annotating an override that does nothing but `return parent::findAll();` is the entire recipe, exactly as it is in Spring. The four reads that answer a question *about* rows rather than returning them — `existsById()`, `count()`, `existsByExample()`, `countByExample()` — go straight to `query()` and deliberately never touch `reading()`: an entity graph means nothing to a `COUNT(*)`, and eager-loading relations to throw them away would be pure cost. A named graph the repository never declared in `$entityGraphs` is a `ConfigurationException` at first use.

!!! warning "Two combinations that do not work the way you would hope"
    A `#[Projection]` and a trailing `Pageable` **do not combine** — the projection returns the whole, unpaged result. Page the entities, or put a `LIMIT` in the `#[Query]`. And `#[Query]` binds **positionally**: named `:placeholders` are rewritten to `?` in order of first appearance, so a placeholder used twice needs the argument passed twice.

---

## Pagination: `Page`, `Pageable`, and `Sort`

Three small, immutable value objects carry a page request in and a page result out, end to end. `Pageable` is the request — a 1-based page number, a size, and an optional `Sort`:

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
```

`Sort` and `Order` compose an ordering out of small, immutable pieces — every combinator returns a *new* `Sort`, never mutates the one you started with:

<!-- source: packages/data/src/Repository/Sort.php -->
```php
final readonly class Sort
{
    /**
     * @param  list<Order>  $orders
     */
    public function __construct(public array $orders = []) {}
    // …
    public static function by(string ...$properties): self
    {
        return new self(array_map(
            static fn (string $property): Order => Order::asc($property),
            array_values($properties),
        ));
    }
    // …
    public function descending(): self
    {
        return new self(array_map(
            static fn (Order $order): Order => Order::desc($order->property),
            $this->orders,
        ));
    }
```

`Order::asc('created_at')`/`Order::desc('created_at')` pair a property name with a `Direction::Asc`/`Direction::Desc` enum whose backing *string value* **is** the Eloquent `orderBy()` direction — `$order->direction->value` needs no translation table at all.

`Page` is what comes back out — the row slice plus everything a client needs to render a pager:

<!-- source: packages/data/src/Repository/Page.php -->
```php
/**
 // …
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
    // …
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

### `Slice`: the page that does not count

`total` is expensive. On a large table, `SELECT COUNT(*)` over the same predicate can cost more than fetching the page itself, and an infinite-scroll list never displays the number anyway — it only needs to know whether to keep the "load more" button. That is what a `Slice` is: everything `Page` has except `total`, plus the one fact the button needs.

<!-- source: packages/data/src/Repository/Slice.php -->
```php
final readonly class Slice
{
    /**
     * @param  list<T>  $items
     */
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
```

`hasNext` is not guessed and it is not counted. The repository fetches **one row more** than the page size, and if that extra row came back it is dropped and remembered as "there is a next page":

<!-- source: packages/data/src/Repository/EloquentRepository.php -->
```php
private function sliceOf(Builder $query, Pageable $pageable): Slice
{
    $take = $pageable->isPaged() ? $pageable->size + 1 : PHP_INT_MAX;

    $rows = $this->applySort($query, $pageable->sort)
        ->skip($pageable->offset())
        ->take($take)
        ->get()
        ->all();

    $hasNext = $pageable->isPaged() && count($rows) > $pageable->size;
```

So a slice is one cheap query where a page is one cheap query *plus* one expensive one. `$repo->findSlice(Pageable::of(3, 20, Sort::by('id')))` is the port's own count-free page; `Slice::nextPageable()` hands you the request for the following one, and `Slice::map()` transforms the items exactly as `Page::map()` does.

A derived method can return a slice too — and here the two halves of the rule matter separately. A **trailing `Pageable` argument** is what makes a derived method page at all; the **declared return type** is what makes it a `Slice` rather than a `Page`:

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
#[EntityGraph(attributePaths: ['entries'])]
public function findByStatusOrderByIdAsc(string $status, Pageable $pageable): Slice
{
    $slice = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert($slice instanceof Slice);

    /** @var Slice<Record> $slice */
    return $slice;
}
```

An undeclared `@method` derived query has no declared return type to read, so a `Pageable` on one always produces a `Page`. That is the same rule the four attributes obey, arriving from a different direction.

---

## Soft delete, auditing, and optimistic locking

Three more building blocks live on `EloquentRepository`, layered on Eloquent's own mechanisms rather than reinvented. None of them is exercised by `Wallet` in this book, but each is one `use` statement away on any model that needs it.

**Soft delete** reuses Eloquent's native `SoftDeletes` trait as-is:

<!-- source: packages/data/tests/Fixtures/Repository/SoftRecord.php -->
```php
final class SoftRecord extends Model
{
    use SoftDeletes;
// …
}
```

Once a model uses it, `delete()`/`deleteById()` through the repository soft-deletes the row, and every ordinary read transparently excludes trashed rows via Eloquent's own global scope. `findAllIncludingDeleted()` and `restore(mixed $id): ?object` round out the lifecycle.

**Auditing** is an opt-in `Auditable` trait that stamps `created_by`/`updated_by` on write, driven by an `AuditorAware` port:

<!-- source: packages/data/src/Repository/Auditing/AuditorAware.php -->
```php
interface AuditorAware
{
    public function currentAuditor(): int|string|null;
}
```

**Optimistic locking** guards concurrent writes with a `version` integer column via `HasOptimisticLock`, which overrides Eloquent's internal `performUpdate()` to add `WHERE version = <loaded version>` to every `UPDATE` — a write against a stale version matches zero rows and throws `OptimisticLockException` instead of silently overwriting someone else's change. That exception extends the kernel's `OptimisticLockingFailureException`, so a `catch` on either name works and the next section's rule holds for it too.

---

## When the driver fails: the `DataAccessException` family

Everything so far has assumed the database says yes. This section is about what reaches your code when it says no — and the framework's answer is Spring's: **a driver failure never leaves `firefly/data` as a driver failure.**

Laravel's own answer is `QueryException`. It is one class for every possible fault, and two things about it make it a poor thing to hand an application. The first is that you cannot branch on it: a duplicate e-mail address and an unreachable database server arrive as the same type, so telling them apart means matching on driver error codes at the call site, once per driver you support. The second is worse. A `QueryException`'s message is the failing statement **with its bindings interpolated** — which is to say the e-mail address, the token or the tenant id that was being written. Anything that renders that message to a client, or logs it at a level somebody else can read, has leaked user data.

`PersistenceExceptionTranslator` fixes both at once:

<!-- source: packages/data/src/Exception/PersistenceExceptionTranslator.php -->
```php
private static function build(?string $kind, Throwable $cause, ?string $sqlState): DataAccessException
{
    $translated = match ($kind) {
        DriverErrorTable::DUPLICATE_KEY => new DuplicateKeyException(previous: $cause),
        DriverErrorTable::INTEGRITY => new DataIntegrityViolationException(previous: $cause),
        DriverErrorTable::DEADLOCK => new DeadlockLoserDataAccessException(previous: $cause),
        DriverErrorTable::LOCK => new CannotAcquireLockException(previous: $cause),
        DriverErrorTable::TIMEOUT => new QueryTimeoutException(previous: $cause),
        DriverErrorTable::TRANSIENT => new TransientDataAccessResourceException(previous: $cause),
        DriverErrorTable::RESOURCE => new DataAccessResourceFailureException(previous: $cause),
        DriverErrorTable::GRAMMAR => new BadSqlGrammarException(previous: $cause),
        default => new DataAccessException('The database refused the operation.', previous: $cause),
    };

    return $sqlState === null ? $translated : $translated->withExtensions(['sqlState' => $sqlState]);
}
```

Read what is *not* in that method. No `$cause->getMessage()` is copied into the new exception. Each of those constructors carries its own **fixed sentence**, written once, with no statement and no bindings in it. The driver's own text stays exactly where it belongs — on `previous`, for the log and the stack trace — and the one piece of the driver's answer that is both harmless and useful, the SQLSTATE, rides along as the `sqlState` extension member of the problem document.

The type is the diagnosis, and it is what a caller branches on:

| Thrown | Extends | HTTP | When |
|---|---|---|---|
| `DuplicateKeyException` | `DataIntegrityViolationException` | 409 | a unique or primary-key violation |
| `DataIntegrityViolationException` | `DataAccessException` | 409 | any other constraint: not-null, foreign key, check |
| `DeadlockLoserDataAccessException` | `CannotAcquireLockException` | 409 | this transaction was chosen as the deadlock victim |
| `CannotAcquireLockException` | `DataAccessException` | 409 | a lock wait timed out |
| `QueryTimeoutException` | `DataAccessException` | 504 | the statement ran past its timeout |
| `TransientDataAccessResourceException` | `DataAccessException` | 503 | the connection dropped — retrying may work |
| `DataAccessResourceFailureException` | `DataAccessException` | 503 | the datasource is not there at all |
| `BadSqlGrammarException` | `DataAccessException` | 500 | the statement is wrong; retrying cannot help |
| `DataAccessException` | `InfrastructureException` | 500 | a failure the tables do not recognise |

The hierarchy is the point. A handler that cares only about "the write conflicted" catches `DataIntegrityViolationException` and gets duplicate keys too; one that cares about "anything the database refused" catches `DataAccessException` and gets all nine. Because every one of them is a `FireflyException`, Chapter 4's problem-details renderer already knows the status code and the error code without a single line of mapping — `DUPLICATE_KEY`, `QUERY_TIMEOUT`, `BAD_SQL_GRAMMAR` and the rest travel to the client as `type`/`code`, and the statement does not travel anywhere.

Two more members of the family come from the repository rather than from a driver: `getById($id)` throws `EmptyResultDataAccessException` (404) exactly where `findById()` would have returned `null`, and `findOneByExample()` throws `IncorrectResultSizeDataAccessException` when the probe matched more than one row.

### Where the translation happens, and where it deliberately does not

<!-- source: packages/data/src/Exception/PersistenceExceptionTranslator.php -->
```php
public function translate(Throwable $e, ?string $driver = null): Throwable
{
    if (! $this->enabled || $e instanceof DataAccessException) {
        return $e;
    }
    // …
    if (! $e instanceof PDOException) {
        return $e;
    }
    // …
    $driver ??= $e instanceof QueryException ? self::driverOf($e->getConnectionName()) : null;
    $sqlState = self::sqlState($e);
    $kind = DriverErrorTable::kindFor($driver, self::driverCode($e), $sqlState);
```

There are exactly two seams a database error can leave `firefly/data` through, and both translate: every `EloquentRepository` method (that is what the `translating()` wrapper you saw around `save()` and `findBySpecification()` is doing), and `TransactionTemplate::execute()` — which means every `#[Transactional]` method in the next chapter, since the proxy delegates to the template. A raw `DB::` call you make outside both still throws Laravel's `QueryException` exactly as it always did. Nothing is patched globally, and nothing is monkey-patched at all.

Three guards keep that narrow. A throwable **already** in the family is returned untouched, so a repository call translating inside a template that translates again is idempotent rather than double-wrapped. Anything that is not a `PDOException` passes straight through — with an exception for the four failures Laravel itself has already classified (`UniqueConstraintViolationException`, `SQLiteDatabaseDoesNotExistException`, `LostConnectionException`, `DeadlockException`), whose own answer is at least as good as any table's, and which are looked for **on the cause as well** because Laravel wraps whatever the query callback throws. And the whole mechanism is one key away from off: `firefly.data.exception-translation.enabled`, `true` by default.

Which code table applies is decided by the driver name. The tables themselves — driver error codes for sqlite, mysql, mariadb and sqlsrv, exact SQLSTATEs, and SQLSTATE *classes* as the fallback — live in a single file, `DriverErrorTable`, with one test row per table row. sqlite needs one extra step, and it is in the source rather than hidden: sqlite reports every constraint violation as code 19, so the unique/primary-key family is only visible in the message text, and that is the one place the translator reads a driver message at all.

!!! tip "The rule worth remembering"
    Catch the **type**, log the **`previous`**, render the **fixed sentence**. If you ever find yourself reading `$e->getMessage()` to decide what happened, the type you needed already exists.

---

## The domain-event bridge

`EloquentRepository::save()` does one thing beyond persisting the row that matters enormously for Chapter 6: if the saved entity also implements `RecordsDomainEvents` — which `Wallet` does — **and** a transaction is currently active on that entity's own connection, `save()` registers the entity with `AggregateTracker`, `firefly/data`'s unit-of-work registry:

<!-- source: packages/data/src/Repository/EloquentRepository.php -->
```php
public function save(object $entity): object
{
    return $this->translating(function () use ($entity): object {
        if ($entity instanceof Model) {
            $entity->save();
        }

        if ($entity instanceof RecordsDomainEvents
            && $this->tracker !== null
            && $this->connectionFor($entity)->transactionLevel() > 0) {
            $this->tracker->track($entity);
        }

        return $entity;
    });
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
| `Example` / `ExampleMatcher` | Query by example, ported *as* a `Specification`, so it composes and pages like any other predicate |
| `#[Modifying]` / `#[Projection]` / `#[Lock]` / `#[EntityGraph]` | What a **declared** method can say that its name cannot: writes, DTO hydration, row locks, eager loading |
| `Page` / `Pageable` / `Sort` / `Order` | Immutable value objects carrying a page request in and a page result (with metadata) out |
| `Slice` | The same page without the count query: one row over-fetched answers `hasNext` |
| `SoftDeletes` / `Auditable` / `HasOptimisticLock` | Opt-in traits for soft-delete, `created_by`/`updated_by` stamping, and version-guarded writes |
| `DataAccessException` family | A driver failure becomes a typed exception with a fixed sentence; the statement never leaves the log |
| The domain-event bridge | `save()` registers a `RecordsDomainEvents` entity with `AggregateTracker` while a transaction is active |

---

## Try it yourself {.exercises}

1. **Add a derived counter.** In a scratch copy of the project, declare `public function countByCurrency(string $currency): int` with no body on a repository extending `EloquentRepository`, and confirm calling it compiles to `SELECT COUNT(*) … WHERE currency = ?` with no SQL of your own.
2. **Compose two specifications.** Build a `Specification` for "balance at least N" and another for "in currency C" with `Specifications::where(...)`, combine them with `Specifications::allOf(...)`, and run the composite through `findBySpecificationPaged` against a small seeded table.
3. **Turn a page into a slice.** Take the paged read from the previous exercise and ask for `findSlice(Pageable::of(1, 2))` instead. Enable the query log and confirm what the section claims: the slice runs **one** query where the page ran two, and `hasNext` is still right.
4. **Make the database say no.** Add a unique index to the wallets table, save two wallets with the same owner id, and catch the result. Check three things: the type is `DuplicateKeyException`, `getMessage()` does *not* contain the owner id you wrote, and `getPrevious()` is Laravel's `QueryException` with the statement still on it.
5. **Read a page of wallets.** Call `EloquentWalletRepository::findAll(...)`'s paging counterpart (add a `findPaged` caller through `WalletRepository` in your scratch project) with `Pageable::of(1, 2, Sort::by('created_at')->descending())` against three seeded wallets, and confirm `total`, `totalPages`, and `hasNext` all match what you expect before and after `Page::map()`.

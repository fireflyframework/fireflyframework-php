# Transactions

`firefly/data`'s `#[Transactional]` is LaraFly's declarative transaction-demarcation model (a Spring
`@Transactional` analog) over Laravel's own connection: a proxy generated at scan time wraps every annotated
method in a `TransactionInterceptor` that drives transaction boundaries through **manual**
`DB::beginTransaction()`/`commit()`/`rollBack()` — never `DB::transaction($closure)` — because only manual
control lets a caught exception be committed-and-rethrown (when it matches `noRollbackFor`, or matches neither
list) instead of unconditionally rolled back.

## The `#[Transactional]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Transactional
{
    public function __construct(
        public Propagation $propagation = Propagation::REQUIRED,
        public Isolation $isolation = Isolation::DEFAULT,
        public bool $readOnly = false,
        public array $rollbackFor = [Throwable::class],
        public array $noRollbackFor = [],
        public ?string $connection = null,
        public ?int $timeout = null,
    ) {}
}
```

On a **class**, it sets the default for every public method. On a **method**, it *replaces* — does not merge
with — the class-level attribute for that one method (Spring semantics): a method-level `#[Transactional]`
is the complete, effective configuration for that method, not an override of individual fields.

```php
#[Transactional(readOnly: true)]        // class default: every public method is read-only REQUIRED
class TransferService
{
    #[Transactional(propagation: Propagation::REQUIRES_NEW)]   // replaces the class default entirely for transfer()
    public function transfer(int $amount): int { /* ... */ }

    public function balance(): int { /* ... */ }   // inherits the class-level readOnly default
}
```

Default `rollbackFor = [Throwable::class]`: PHP has no checked/unchecked exception split, so by default *any*
throwable rolls back the transaction, unless it also matches `noRollbackFor` (which always wins).

## The seven propagation modes

`Propagation` is an unbacked enum with all seven Spring modes, including `NESTED` (which Laravel's automatic
savepoints make possible over a plain relational connection):

| Mode | Behaviour |
|---|---|
| `REQUIRED` *(default)* | Joins the caller's transaction if one is active; otherwise starts a new outermost one. |
| `REQUIRES_NEW` | Always starts a transaction. If none is active it becomes the new outermost transaction; if one is **already active on the same connection**, Laravel has no suspend primitive, so it degrades to a nested `beginTransaction()` — i.e. a savepoint, not a truly independent transaction (see [Known-latent](#known-latent)). |
| `NESTED` | Same underlying mechanics as `REQUIRES_NEW` in this implementation: a fresh outermost transaction if none is active, otherwise a nested `beginTransaction()` that Laravel turns into a savepoint — so a `NESTED` failure unwinds only to its own savepoint, not the whole unit of work. |
| `SUPPORTS` | Runs in the caller's transaction if one is active; otherwise runs with no transaction at all. Never starts one. |
| `NOT_SUPPORTED` | Always runs with no transaction. On the same connection there is no suspend primitive, so an already-active transaction is simply **not** paused — the work still runs inside it (see [Known-latent](#known-latent)). |
| `MANDATORY` | Requires an active transaction; runs in it if present, otherwise throws `TransactionRequiredException`. |
| `NEVER` | Forbids an active transaction; throws `TransactionNotAllowedException` if one is active, otherwise runs with none. |

`TransactionTemplate::execute()` is the single source of truth both the generated proxy and any programmatic
caller go through — there is no second code path to keep in sync.

## Isolation, read-only, and rollback rules

`Isolation` is a string-backed enum whose value **is** the SQL clause:

```php
enum Isolation: string
{
    case DEFAULT = 'DEFAULT';                  // no SET at all — leaves the connection's own default
    case READ_UNCOMMITTED = 'READ UNCOMMITTED';
    case READ_COMMITTED = 'READ COMMITTED';
    case REPEATABLE_READ = 'REPEATABLE READ';
    case SERIALIZABLE = 'SERIALIZABLE';
}
```

On the outermost transaction of a unit of work, a non-`DEFAULT` isolation issues `SET TRANSACTION ISOLATION
LEVEL {value}`; `readOnly: true` issues `SET TRANSACTION READ ONLY`. Both are **best-effort**: either
statement failing (a driver that doesn't support it) is caught and silently ignored rather than failing the
whole transaction — see [Known-latent](#known-latent).

`rollbackFor`/`noRollbackFor` are evaluated in that order when the wrapped work throws:

1. If the thrown exception is an instance of anything in `noRollbackFor`, the transaction **commits** and the
   exception is rethrown (`noRollbackFor` always wins, even over a matching `rollbackFor`).
2. Otherwise, if it matches `rollbackFor` (default `[Throwable::class]`, i.e. everything), the transaction
   **rolls back** and the exception is rethrown.
3. Otherwise (matches neither list — only reachable with a narrowed `rollbackFor`), the transaction
   **commits** and the exception is rethrown.

```php
#[Transactional(noRollbackFor: [IgnorableException::class])]
public function logButKeep(): void
{
    DB::table('accounts')->insert(['name' => 'kept']);

    throw new IgnorableException('ignored');   // insert survives: commit-and-rethrow
}
```

Either way — commit or roll back — after-commit domain events queued during the unit of work are drained via
`DomainEventDispatcher::dispatchAfterCommit()` *before* the transaction is resolved, on the descriptor's own
`connection`, so a `#[Transactional(connection: 'x')]` method fires its listeners on `x`'s commit; Laravel
discards `afterCommit` callbacks on rollback, so a listener never sees an event from a rolled-back unit of
work. See [Domain (DDD)](domain.md#the-after-commit-event-model).

## The proxy model

A `#[Transactional]` bean is not called directly — `TransactionalBeanPostProcessor` (a `#[Component]`
discovered by its `BeanPostProcessor` interface and installed by `RegisterBeanPostProcessorsPass` at **phase
700**) swaps it, on the second BPP pass (after `#[PostConstruct]` has already run on the real bean), for an
instance of a generated `final class {Target}__FireflyTransactionalProxy extends {Target}`. That class
overrides every transactional method with:

```php
public function transfer(int $amount): int
{
    return $this->__fireflyTxInterceptor->run(
        fn () => parent::transfer($amount),
        self::__fireflyTxDescriptor('transfer'),
    );
}
```

— routing the real call through `TransactionInterceptor::run()` (which delegates to
`TransactionTemplate::execute()`) before falling through to `parent::`. `ProxyFactory` instantiates the proxy
**state-preservingly**: `newInstanceWithoutConstructor()` (so `#[PostConstruct]` is not re-run), then a bound
closure copies the real bean's scope-visible state via `get_object_vars()` — not `ReflectionProperty` — onto
the proxy, and a second bound closure sets the proxy's own private interceptor property. The proxy *is-a*
`{Target}`, so container calls and `#[PreDestroy]` resolve against it exactly as they would the original bean.

**Self-invocation bypasses the proxy** — the same well-known Spring limitation. A method calling
`$this->otherMethod()` from inside the proxied class calls straight through `parent::`, skipping the
interceptor entirely. To get transactional semantics for an inner unit of work from within another method,
call through the injected `TransactionTemplate` instead:

```php
#[Service]
#[Transactional]
class AccountService
{
    public function __construct(private readonly TransactionTemplate $template) {}

    public function outerWithNested(): void
    {
        DB::table('accounts')->insert(['name' => 'outer']);

        try {
            $this->template->execute(function (): void {
                DB::table('accounts')->insert(['name' => 'inner']);
                throw new RuntimeException('inner fail');
            }, new TransactionalDescriptor(propagation: Propagation::NESTED));
        } catch (RuntimeException) {
            // outer commit is unaffected — only the NESTED savepoint unwound
        }
    }
}
```

`TransactionTemplate::execute()` is the programmatic twin of `#[Transactional]` for exactly this case (or for
any transactional unit of work that isn't a whole bean method):

```php
$template->execute(function (): void {
    DB::table('widgets')->insert(['name' => 'a']);
    DB::table('widgets')->insert(['name' => 'b']);
});                                        // no descriptor -> REQUIRED / default isolation / rollback-on-Throwable

$template->execute($work, new TransactionalDescriptor(
    propagation: Propagation::REQUIRES_NEW,
    noRollbackFor: [IgnorableException::class],
));
```

## Known-latent

- **The manifest and its proxies must stay one matched unit — and they now are, on both boot paths.**
  `DataAutoConfiguration::transactionalManifest()` resolves the compiled `transactional.php` if
  `firefly:cache` wrote one (registering the `proxies.php` classmap autoloader first, so `firefly/cli` is not
  required at runtime), otherwise scans `firefly.scan.paths` and materialises each
  `{Target}__FireflyTransactionalProxy` per process through `ProxyMaterializer` — a private `0700` directory
  written with `O_EXCL`, dev-time cost only. Proxies are made loadable **before** the manifest is handed out,
  because `TransactionalBeanPostProcessor` throws a `ConfigurationException` when the manifest promises a
  proxy class it cannot find; a half-emitted cache therefore fails at boot rather than quietly running
  unproxied. Until this landed, the auto-config bound an unconditional empty manifest and *nothing* loaded
  the compiled `transactional.php`, so `#[Transactional]` was a **silent no-op** in any application that did
  not hand-write its own manifest configuration — which is precisely what the skeleton's
  `app/Support/CachedTransactionalConfiguration.php` existed to do, and why it has been deleted.
- **The proxy's state-copy cannot see state private to a non-framework parent of the proxied class.**
  `ProxyFactory`'s scoped closure copies `get_object_vars()` visible from `$declaredClass`'s own scope; state
  declared `private` on some class *above* `$declaredClass` in its inheritance chain is invisible to it. A
  typical service or repository holds its own fields (not a private-parent's), so this is unaffected in
  practice.
- **`REQUIRES_NEW`/`NOT_SUPPORTED` cannot truly suspend an active transaction on the same connection** —
  Laravel has no suspend primitive. `REQUIRES_NEW` is genuinely independent only when it targets a distinct
  configured `connection` from the caller's; on the *same* connection it degrades to a nested savepoint
  instead. `NOT_SUPPORTED` on the same connection cannot pause the ambient transaction either — the work still
  runs inside it rather than truly outside a transaction.
- **Isolation, read-only, and timeout are driver-dependent.** The `SET TRANSACTION ISOLATION LEVEL`/`SET
  TRANSACTION READ ONLY` statements are issued best-effort and swallowed on failure — SQLite, for instance,
  ignores or limits both. `timeout` is currently best-effort/reserved (carried on the descriptor and the
  manifest, not yet enforced as a hard statement timeout).
- **Auditing's `created_by`/`updated_by` no-op until the M11 security-context principal is bound** — see
  [Relational Data](data-relational.md#auditing) for the full behaviour and how it turns on.
- **Auto after-commit dispatch covers aggregates saved through a Firefly repository** (`EloquentRepository::
  save()` registering with `AggregateTracker`) within the transaction. For a recorder not saved that way, use
  the explicit `DomainEventDispatcher::publishAfterCommit($aggregate, $connection)` escape hatch to get the
  same after-commit-only publish guarantee.

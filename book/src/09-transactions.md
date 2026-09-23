<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 9</span>

# Transactions and the `#[Transactional]` Proxy {.chtitle}

By the end of this chapter you will know exactly what `#[Transactional]` does — its seven propagation modes, its isolation/read-only/rollback settings, and the generated proxy that gives it teeth — how self-invocation bypasses that proxy (and what to do instead), how `firefly:cache` and the in-process scan together give every application a working proxy with no wiring of its own, and the single most consequential fact this whole book has been building toward: **a domain event publishes only because `TransactionTemplate` — the machinery behind `#[Transactional]` — is the sole caller of the after-commit dispatch.** No `#[Transactional]`, no publish, no matter how correctly an aggregate raised its event.

!!! note "New term: declarative transaction demarcation"
    Instead of writing `DB::beginTransaction()` / `DB::commit()` / `DB::rollBack()` by hand inside a method body, you *declare* the boundary with an attribute and let a generated proxy enforce it. This is Spring's `@Transactional` model, and it is why Chapters 6 and 7 could already show you `#[Transactional]` on `OpenWalletHandler`, `DepositHandler`, `WithdrawHandler`, and `TransferHandler` without a single explicit `DB::` call inside any of their `handle()` bodies.

---

## The `#[Transactional]` attribute

<!-- source: packages/data/src/Transaction/Attributes/Transactional.php -->
```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Transactional
{
    /**
     * @param  list<class-string<Throwable>>  $rollbackFor
     * @param  list<class-string<Throwable>>  $noRollbackFor
     */
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

On a **class**, it sets the default for every public method. On a **method**, it *replaces* — never merges with — the class-level attribute for that one method (the same Spring semantics). `packages/data/tests/Fixtures/Capstone/AccountService.php` is real, shipped test fixture code that puts both forms to work at once:

<!-- source: packages/data/tests/Fixtures/Capstone/AccountService.php -->
```php
<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;
// …
#[Service]
#[Transactional]
class AccountService
{
    public function __construct(private readonly TransactionTemplate $template) {}

    public function transferAndCommit(): void
    {
        DB::table('accounts')->insert(['name' => 'a']);
        DB::table('accounts')->insert(['name' => 'b']);
    }

    public function transferAndFail(): void
    {
        DB::table('accounts')->insert(['name' => 'a']);
        DB::table('accounts')->insert(['name' => 'b']);

        throw new RuntimeException('boom');
    }
    // …
    #[Transactional(noRollbackFor: [IgnorableException::class])]
    public function logButKeep(): void
    {
        DB::table('accounts')->insert(['name' => 'kept']);

        throw new IgnorableException('ignored');
    }

    public function outerWithNested(): void
    {
        DB::table('accounts')->insert(['name' => 'outer']);

        try {
            $this->template->execute(function (): void {
                DB::table('accounts')->insert(['name' => 'inner']);

                throw new RuntimeException('inner fail');
            }, new TransactionalDescriptor(propagation: Propagation::NESTED));
        } catch (RuntimeException) {
        }
    }
}
```

Every public method here inherits the class-level `#[Transactional]` default (`REQUIRED`, rollback on any `Throwable`) **except** `logButKeep()`, whose own method-level attribute *completely replaces* that default for this one method — it does not layer `noRollbackFor` on top of the class default, it *is* the effective configuration. Default `rollbackFor = [Throwable::class]`: PHP has no checked/unchecked exception split, so by default *any* throwable rolls the transaction back, unless it also matches `noRollbackFor`, which always wins.

---

## The seven propagation modes

`Propagation` is an unbacked enum with every Spring mode, including `NESTED` — made possible over a plain relational connection by Laravel's own automatic savepoints:

<!-- source: packages/data/src/Transaction/Propagation.php -->
```php
enum Propagation
{
    case REQUIRED;
    case REQUIRES_NEW;
    case NESTED;
    case SUPPORTS;
    case NOT_SUPPORTED;
    case MANDATORY;
    case NEVER;
// …
}
```

`TransactionTemplate::execute()` is the single source of truth both the generated proxy and any direct, programmatic caller run through — there is no second code path to keep in sync:

<!-- source: packages/data/src/Transaction/TransactionTemplate.php -->
```php
public function execute(Closure $work, ?TransactionalDescriptor $descriptor = null): mixed
{
    $d = $descriptor ?? new TransactionalDescriptor;
    $connection = DB::connection($d->connection);
    $active = $connection->transactionLevel() > 0;

    try {
        return match ($d->propagation) {
            Propagation::MANDATORY => $active ? $work() : throw new TransactionRequiredException,
            Propagation::NEVER => $active ? throw new TransactionNotAllowedException : $work(),
            Propagation::SUPPORTS, Propagation::NOT_SUPPORTED => $work(),
            Propagation::REQUIRED => $active ? $work() : $this->runInTransaction($connection, $work, $d, true),
            Propagation::REQUIRES_NEW, Propagation::NESTED => $this->runInTransaction($connection, $work, $d, ! $active),
        };
    } catch (Throwable $e) {
        throw $this->translator->translate($e, $connection->getDriverName());
    }
}
```

The whole method is one `match` over the propagation mode and a `catch` that hands whatever came out to the exception translator from Chapter 5. Everything else — the savepoints, the isolation statements, the timeout, the after-commit drain — is inside `runInTransaction()`, which only three of the seven modes ever reach.

| Mode | Behaviour |
|---|---|
| `REQUIRED` *(default)* | Joins the caller's transaction if one is active; otherwise starts a new outermost one. |
| `REQUIRES_NEW` | Always runs in a transaction. With none active it becomes the new outermost one; with one **already active on the same connection**, Laravel has no suspend primitive, so it degrades to a nested `beginTransaction()` — a savepoint, not a truly independent transaction. |
| `NESTED` | The same mechanics as `REQUIRES_NEW` in this implementation: a fresh outermost transaction if none is active, otherwise a savepoint — so a `NESTED` failure unwinds only to its own savepoint, never the whole unit of work. |
| `SUPPORTS` | Runs in the caller's transaction if one is active; otherwise with no transaction at all. Never starts one. |
| `NOT_SUPPORTED` | Always runs with no transaction — but on the *same* connection there is no suspend primitive, so an already-active transaction is simply not paused; the work still runs inside it. |
| `MANDATORY` | Requires an active transaction; runs in it, or throws `TransactionRequiredException`. |
| `NEVER` | Forbids an active transaction; throws `TransactionNotAllowedException` if one is active, otherwise runs with none. |

`outerWithNested()` above is `NESTED` in action: the outer method inserts `'outer'` under the class-level `REQUIRED` default, then calls `$this->template->execute(...)` directly with `propagation: Propagation::NESTED`, inserts `'inner'`, and throws. A real, shipped capstone test proves exactly what unwinds and what survives:

<!-- source: packages/data/tests/CapstoneTransactionalIntegrationTest.php -->
```php
it('unwinds a NESTED inner rollback to a savepoint, leaving the outer row intact', function () {
    // …
    accountService($this->app())->outerWithNested();

    expect(DB::table('accounts')->pluck('name')->all())->toBe(['outer']);
});
```

The `'inner'` row vanishes with the savepoint; `'outer'` — inserted before the nested unit of work even began — survives and later commits normally when the outer method returns.

---

## Isolation, read-only, and the rollback decision

`Isolation` is a string-backed enum whose value **is** the SQL clause:

<!-- source: packages/data/src/Transaction/Isolation.php -->
```php
enum Isolation: string
{
    case DEFAULT = 'DEFAULT';
    case READ_UNCOMMITTED = 'READ UNCOMMITTED';
    case READ_COMMITTED = 'READ COMMITTED';
    case REPEATABLE_READ = 'REPEATABLE READ';
    case SERIALIZABLE = 'SERIALIZABLE';
}
```

On the outermost transaction of a unit of work, a non-`DEFAULT` isolation issues `SET TRANSACTION ISOLATION LEVEL {value}`, and `readOnly: true` issues `SET TRANSACTION READ ONLY` — both **best-effort**: either statement failing (a driver that ignores it, like SQLite) is caught and silently ignored rather than failing the whole unit of work.

<!-- source: packages/data/src/Transaction/TransactionTemplate.php -->
```php
private function shouldRollBack(Throwable $translated, Throwable $original, TransactionalDescriptor $d): bool
{
    foreach ($d->noRollbackFor as $type) {
        if ($translated instanceof $type || $original instanceof $type) {
            return false; // noRollbackFor wins: commit-and-rethrow
        }
    }

    foreach ($d->rollbackFor as $type) {
        if ($translated instanceof $type || $original instanceof $type) {
            return true;
        }
    }

    return false; // not listed in rollbackFor: commit-and-rethrow
}
```

Read in order: an exception matching `noRollbackFor` always **commits** (checked first, so it wins even over a matching `rollbackFor`); otherwise, a match in `rollbackFor` (the default is everything) **rolls back**; otherwise — only reachable with a deliberately narrowed `rollbackFor` — it **commits**. `AccountService::logButKeep()` exercises exactly the first branch, and a real capstone test proves the row survives the exception it's thrown alongside:

<!-- source: packages/data/tests/CapstoneTransactionalIntegrationTest.php -->
```php
it('commits despite a method-level noRollbackFor exception (override beats class-level)', function () {
    // …
    try {
        accountService($this->app())->logButKeep();
    } catch (IgnorableException) {
    }

    // Class-level default would roll back any Throwable; the method-level override keeps the row.
    expect(DB::table('accounts')->where('name', 'kept')->count())->toBe(1);
});
```

Notice `TransactionTemplate` uses **manual** `beginTransaction()`/`commit()`/`rollBack()` throughout, never Laravel's own `DB::transaction($closure)` — only manual control lets a caught exception be *committed*-and-rethrown when `noRollbackFor` says so, instead of Laravel's own helper, which always rolls back on any exception with no such override point.

---

## The proxy model

A `#[Transactional]` bean is never called directly. `TransactionalBeanPostProcessor` — a `BeanPostProcessor` discovered exactly like any other bean, installed at phase 700 — swaps it, on its second pass (after `#[PostConstruct]` has already run on the real bean), for an instance of a generated `final class {Target}__FireflyTransactionalProxy extends {Target}`. `ProxyClassGenerator` emits that class, overriding every transactional method with:

<!-- illustrative: the source ProxyClassGenerator emits for an application's own #[Transactional] service; a generated proxy is written to a private temporary file at wrap time and is in no file in this repository -->
```php
final class TransferService__FireflyTransactionalProxy extends TransferService
{
    private \Firefly\Data\Proxy\MethodInterceptor $__fireflyTxInterceptor;

    public function transfer(int $amount): int
    {
        return (new \Firefly\Data\Proxy\MethodInvocation(
            $this,
            TransferService::class,
            'transfer',
            [$amount],
            [$this->__fireflyTxInterceptor],
            [\Firefly\Data\Transaction\TransactionalDescriptor::class => self::__fireflyTxDescriptor('transfer')],
            fn (array $__fireflyArgs) => parent::transfer(...$__fireflyArgs),
        ))->proceed();
    }
}
```

— handing the call to `MethodInvocation::proceed()`, which walks the interceptors the compiled plan named for this method and finishes in the terminal closure that calls `parent::`. For a bean whose only advice is `#[Transactional]` that list holds one link: `TransactionInterceptor::invoke()` reads the baked `TransactionalDescriptor` off the invocation and passes `fn () => $invocation->proceed()` to its unchanged `run()`, which delegates straight to `TransactionTemplate::execute()`. A real capstone test confirms the swap actually happened — the resolved bean's class is **not** the plain service class at all:

<!-- source: packages/data/tests/CapstoneTransactionalIntegrationTest.php -->
```php
it('proxies the #[Service] and rolls back BOTH inserts when the method throws', function () {
    // …
    $service = accountService($this->app());

    expect($service::class)->not->toBe(AccountService::class); // it is the generated proxy subclass
    expect($service)->toBeInstanceOf(AccountService::class);

    try {
        $service->transferAndFail();
    } catch (RuntimeException) {
    }

    expect(DB::table('accounts')->count())->toBe(0);
});
```

`$service::class` is the generated `AccountService__FireflyTransactionalProxy`, yet `$service instanceof AccountService` is still true — the proxy *is-a* `{Target}`, so every container call and `#[PreDestroy]` resolve against it exactly as they would the original bean. `ProxyFactory` instantiates it **state-preservingly**: `newInstanceWithoutConstructor()` (so `#[PostConstruct]` is not re-run), then the real bean's initialised state is copied onto the proxy slot by slot, each slot written by a closure bound to the class that *declares* it — never `ReflectionProperty::setValue()`. Writing from the declaring class is not a detail: a closure bound to the declared class alone sees only that class's own privates, so writing from where each slot was declared is what lets the copy reach a `private` on a parent (`EloquentRepository`'s translator, under every `#[Repository]`) and initialise a parent's `protected readonly` (`EloquentRepository`'s manifest and tracker) on the PHP 8.3 floor, where a `readonly` property is initialisable from its declaring class's scope alone. The `(array)` cast is what makes the slots exact: it mangles a private as `"\0Owner\0name"`, so a private a parent and a child both declare under one name stays two slots, and a typed property the bean never initialised is simply absent and stays uninitialised on the proxy.

!!! warning "Self-invocation bypasses the proxy"
    A method calling `$this->otherMethod()` from *inside* the proxied class calls straight through `parent::`, skipping `__fireflyTxInterceptor` entirely — the same well-known Spring limitation. This is exactly why `AccountService::outerWithNested()` above doesn't just call some hypothetical `$this->innerNested()` method — it goes through the **injected `TransactionTemplate`** instead, which is the correct escape hatch for getting transactional semantics on an inner unit of work from within another method on the same instance.

---

## One proxy, many advices

That proxy is no longer only about transactions. Any package can contribute a kind of advice by shipping one `#[Component]` implementing `AdviceSource` — `advice()` names its interceptor bean, its descriptor class and an **order**; `scan()` returns the rows it claims; `render()` turns a row back into the PHP literal the generated class bakes in. `ProxyPlanner` merges every source's rows into one `ProxyPlan`, applying the sources in `Advice::order`, so each method's advice list is outermost-first by construction. The generated class keeps the name it has always had, `{Target}__FireflyTransactionalProxy`, but it now declares one private interceptor property and one baked static descriptor factory **per advice kind** the class uses, and `MethodInvocation::proceed()` walks that list before reaching the terminal `fn (array $__fireflyArgs) => parent::m(...$__fireflyArgs)`. Lower `Advice` order runs *outer*: `MethodSecurityAdviceSource`'s advice is `100` and the transactional one is `1000`, so a refusal is thrown before a transaction is ever opened. An advice whose interceptor bean is absent fails the boot with a `ConfigurationException` unless it declared `inertWhenUnbound` — security's does, because "annotations are inert until `firefly.security.enabled` is on" is its documented state — and `InterceptorRegistry` then hands the proxy a `PassThroughInterceptor` in its place.

::: figure art/figures/method-interceptor-chain.svg | Figure 9.1 — Every AdviceSource contributes rows to one compiled ProxyPlan; a call then runs method security at advice order 100 before the transaction at 1000.

---

## The timeout is enforced, not carried

`#[Transactional(timeout: 5)]` used to be metadata a reader could set and nothing would read. It is now enforced, and the way it is enforced is worth understanding, because one half of it can interrupt a running statement and the other half cannot.

<!-- source: packages/data/src/Transaction/TransactionTemplate.php -->
```php
$timeout = $outermost ? $this->effectiveTimeout($d) : 0;
$restore = $timeout > 0 && $this->settings->statementTimeout ? $this->timeouts->apply($connection, $timeout) : null;
// (int): hrtime(true) is an int on every 64-bit build; the cast keeps PHPStan's int|float|false union out.
$deadline = $timeout > 0 ? (int) hrtime(true) + $timeout * 1_000_000_000 : null;
```

**The wall clock** is the half that always applies. A monotonic deadline is taken right after `beginTransaction()`, and when the work *returns* the template compares the clock against it. Overrun means roll back and throw `TransactionTimedOutException` — 504, error code `TRANSACTION_TIMED_OUT`:

<!-- source: packages/data/src/Transaction/TransactionTemplate.php -->
```php
if ($deadline !== null && (int) hrtime(true) > $deadline) {
    // Drain the tracker either way (it must not leak into the next unit of work); the rollback discards
    // the after-commit callbacks it queued.
    $this->dispatcher?->dispatchAfterCommit($d->connection);
    $connection->rollBack();

    throw new TransactionTimedOutException(sprintf(
        'The transaction ran for longer than its %d second timeout and was rolled back.',
        $timeout,
    ));
}
```

Note what that cannot do: PHP is not going to interrupt a `SELECT` that is still inside the driver. The wall-clock check judges the method *after* it returns, which catches an overrun but does not stop one.

**The driver statement timeout** is the half that can. Immediately after `beginTransaction()` on the outermost transaction, `StatementTimeoutApplier` tells the database itself to give up, in whatever dialect that database speaks — `SET LOCAL statement_timeout` on pgsql (transaction-scoped, which is precisely why it must be issued after the `BEGIN`), `max_execution_time` plus `innodb_lock_wait_timeout` on mysql, mariadb's own `max_statement_time` on mariadb, and the busy timeout on sqlite, which is the only knob sqlite has. `apply()` returns the step that undoes it, and the template runs that in its `finally` — because the mysql variables are *session* variables, and on a persistent connection the next request would otherwise inherit this request's budget.

Every one of those statements is best-effort, exactly like the isolation `SET`: a driver that refuses one does not fail the transaction, and the wall-clock check still applies. You can turn the driver half off entirely with `firefly.data.transaction.statement-timeout=false` and keep the wall clock.

Three rules decide the number itself, and a test pins each one:

<!-- source: packages/data/tests/Transaction/TransactionTimeoutTest.php -->
```php
it('applies firefly.data.transaction.default-timeout when the attribute names none, and the attribute wins when it does', function () {
    $template = new TransactionTemplate(null, null, new DataSettings(defaultTimeout: 1));

    expect(fn () => $template->execute(overrunOneSecond(...)))->toThrow(TransactionTimedOutException::class)
        ->and(DB::table('widgets')->count())->toBe(0);

    $template->execute(overrunOneSecond(...), new TransactionalDescriptor(timeout: 10));

    expect(DB::table('widgets')->count())->toBe(1);
});
```

`#[Transactional(timeout:)]` wins over `firefly.data.transaction.default-timeout`; `0` on both means no deadline at all; and a **joined** transaction never times out on its own — only the outermost transaction takes a deadline, which is the Spring rule and the only one that makes sense when an inner method's budget would otherwise cut short work the outer method is still responsible for.

---

## `#[TransactionalEventListener]`: hearing an event in a phase

Chapter 8's `#[AsEventListener]` hears an event when it is published. Inside a `#[Transactional]` method, "when it is published" is often the wrong moment: the row is written but not committed, so a listener that sends an e-mail or enqueues a job may be acting on a transaction that is about to roll back.

There are two different answers to that, and it is worth being precise about which one you want:

- `DomainEventDispatcher::publishAfterCommit()` — covered earlier in this chapter — defers **the event**. Nobody hears it at all before the commit.
- `#[TransactionalEventListener]` defers **the listener**. The event is published immediately (a plain `#[AsEventListener]` on the same class still sees it inside the transaction); *this* method is queued on the current transaction and invoked in the phase it asked for.

<!-- source: packages/data/src/Transaction/TransactionPhase.php -->
```php
enum TransactionPhase: string
{
    case BEFORE_COMMIT = 'BEFORE_COMMIT';
    case AFTER_COMMIT = 'AFTER_COMMIT';
    case AFTER_ROLLBACK = 'AFTER_ROLLBACK';
    case AFTER_COMPLETION = 'AFTER_COMPLETION';
}
```

| Phase | When it runs | What it sees |
|---|---|---|
| `BEFORE_COMMIT` | inside the transaction, just before the commit | the uncommitted row, at transaction level 1 — **a throw aborts the commit** |
| `AFTER_COMMIT` (the default) | after a successful commit | the committed row, at level 0 |
| `AFTER_ROLLBACK` | after a rollback | the row gone |
| `AFTER_COMPLETION` | after either | whichever of the two happened |

The framework's own fixture declares one listener per phase, which is the clearest way to read them:

<!-- source: packages/data/tests/Fixtures/Listeners/NoteAudit.php -->
```php
#[TransactionalEventListener(phase: TransactionPhase::BEFORE_COMMIT, order: -10)]
public function beforeCommit(NoteSaved $event): void
{
    self::record('before-commit', $event);

    if ($event->title === 'veto') {
        throw new RuntimeException('vetoed before commit');
    }

    if ($event->title === 'chain') {
        $this->events->publish(new NoteIndexed($event->title));
    }
}
```

Four details, each of which you will eventually need:

- **The event class is inferred** from the method's first parameter type, exactly as `#[AsEventListener]` does it. `event:` is there for the case where you want to be explicit.
- **`order:` follows the `#[Order]` convention** — lower first — among transactional listeners *of the same phase*.
- **No transaction, no listener.** With nothing active the method is skipped, silently and on purpose: it asked to run in a phase that does not exist. `fallbackExecution: true` says "run me at once instead", which is what you want for a listener that must fire either way.
- **The attribute is inert metadata.** `TransactionalScanner` compiles it into the manifest's `listeners` map, `TransactionalEventListenerWiringPass` registers it, and `TransactionSynchronizationRegistry` queues it against the running transaction — no reflection at publish time. The whole mechanism is behind `firefly.data.transactional-event-listeners.enabled`, `true` by default.

!!! tip "`BEFORE_COMMIT` is a veto, and that is the useful part"
    A last-moment invariant check belongs here: it runs inside the transaction, so throwing from it aborts the commit and takes the whole unit of work with it. Publishing from it also works — a second event queued while the `BEFORE_COMMIT` queue is draining is handled inside the same commit, not deferred to the next one.

---

## How a real app actually gets a working proxy

Chapter 2 taught you the shape of a `#[Configuration]` class and its `#[Bean]` factory methods. For one release of this framework, every LaraFly application had to write one of them by hand — a `#[Configuration]` whose single `#[Bean]` loaded the cached `TransactionalManifest` — or `#[Transactional]` did nothing at all. **You no longer need that class**, and it is worth a page: the bug it worked around was one of the sharpest this framework has had, and the fix is a small lesson in how every compiled artifact is resolved.

`php artisan firefly:cache` emits **three** separate artifacts the proxy machinery reads. It runs `TransactionalScanner`/`TransactionalManifestCompiler` — one of the twelve scanner/compiler pairs — to write the manifest data to `bootstrap/cache/firefly/transactional.php`. It runs `ProxyPlanCompiler` over the merged `ProxyPlan` to write `bootstrap/cache/firefly/proxy-plan.php` — the artifact that decides which beans get a proxy at all and which advice each of their methods runs. And it runs `ProxyClassGenerator` to emit one `{Target}__FireflyTransactionalProxy` source file **per class the plan names** — every class any `AdviceSource` claims, so a bean carrying only method-security rules gets one too — into a `proxies/` directory, plus a `proxies.php` classmap.

For a long time, nothing loaded the first of those three. `DataAutoConfiguration` bound an unconditional *empty* `TransactionalManifest`, the compiled `transactional.php` was referenced only by its own declaration, and so `hasProxyFor()` was always false and `TransactionalBeanPostProcessor` handed back every bean unwrapped. `#[Transactional]` was a **silent no-op** in any application that did not hand-write its own manifest configuration — which is exactly what that hand-written `#[Configuration]` existed to do, and exactly the failure mode the previous section warned about, arriving from the framework's own side.

It is fixed at the source. Both beans now resolve their artifact the way every Category-B artifact in this framework is resolved — **compiled file first, in-process scan second, empty last**:

<!-- source: packages/data/src/DataAutoConfiguration.php -->
```php
#[Bean]
#[ConditionalOnMissingBean(TransactionalManifest::class)]
public function transactionalManifest(Container $container): TransactionalManifest
{
    if (($file = AppScan::cachedFile($container, AppScan::TRANSACTIONAL)) !== null) {
        ProxyMaterializer::classmap($container);

        return TransactionalManifest::load($file);
    }

    $paths = AppScan::paths($container);
    if ($paths === []) {
        return new TransactionalManifest([], []);
    }

    return (new TransactionalScanner)->scan($paths);
}
```

Three things in eighteen lines are worth reading slowly. The compiled file wins when it exists, which is the cached-production path. When it does not, the scanner runs in process over `firefly.scan.paths`, which is the development path — no `firefly:cache` required to get a working proxy while you are writing code. And `ProxyMaterializer::classmap()` is called **before** the manifest is returned, so the generated proxy classes are loadable before anything can ask for one; `TransactionalBeanPostProcessor` throws a `ConfigurationException` on a manifest that promises a proxy class it cannot find, so a half-written cache fails loudly at boot instead of quietly running unproxied.

`proxyPlan()` sits beside it with the same shape and one extra rung: `proxy-plan.php` first, then — for a cache written by a `firefly:cache` from before proxy plans existed — a transactional-only plan bridged from the manifest, then the in-process scan through every `AdviceSource` bean, then, with no scan paths at all, a transactional-only plan from whatever `TransactionalManifest` is bound. That last rung is what lets the framework's own capstone fixtures compile a manifest by hand and still get their `#[Transactional]` beans wrapped.

So the skeleton ships no `CachedTransactionalConfiguration`, and neither should your application. A LaraFly app gets a working `#[Transactional]` proxy in development from the scan and in production from the cache, with no wiring of its own. The framework's test suites still supply an equivalent `#[Configuration]` inline — `packages/data/tests/Fixtures/Capstone/CapstoneTransactionalConfiguration` for the `AccountService` fixture above, and `samples/lumen/tests/Support/LumenTransactionalConfiguration` for the handlers this book takes from `samples/lumen` — because those suites do not boot through an application at all and have no `firefly.scan.paths` to scan.

!!! tip "If you wrote one of these classes, delete it"
    A hand-bound `TransactionalManifest` still works — `#[ConditionalOnMissingBean]` makes the framework back off from any competing bean definition — but it now pins your application to whatever that class does, and it was only ever a workaround.

---

## The key lesson: domain events publish only through a `#[Transactional]` boundary

Everything in this section has been building to one fact, and it is the single most important thing this chapter teaches. Look again at where `DomainEventDispatcher::dispatchAfterCommit()` is actually called from inside `TransactionTemplate` — the catch arm, the timeout arm and the success arm of one method:

<!-- source: packages/data/src/Transaction/TransactionTemplate.php -->
```php
if ($outermost) {
    // Queue after-commit events BEFORE resolving the tx, on THIS descriptor's connection: Laravel fires
    // them on that connection's commit, discards on rollBack.
    $this->dispatcher?->dispatchAfterCommit($d->connection);
}

if ($this->shouldRollBack($translated, $e, $d)) {
    $connection->rollBack();
} else {
// …
if ($deadline !== null && (int) hrtime(true) > $deadline) {
    // Drain the tracker either way (it must not leak into the next unit of work); the rollback discards
    // the after-commit callbacks it queued.
    $this->dispatcher?->dispatchAfterCommit($d->connection);
    $connection->rollBack();
// …
if ($outermost) {
    $this->dispatcher?->dispatchAfterCommit($d->connection);
}

$this->commit($connection);

return $result;
```

`dispatchAfterCommit()` is called from exactly **three** places, and all three are inside that one `TransactionTemplate` method — there is no fourth call site anywhere in the framework. Two of the three are there so the tracker is *drained* on the failure paths: draining on a rollback empties it without scheduling any publish, which is what stops one unit of work's events leaking into the next. And `dispatchAfterCommit()` itself only has anything to dispatch because of a second, equally load-bearing fact: `EloquentRepository::save()` (Chapter 5) only registers an entity with `AggregateTracker` when `$connection->transactionLevel() > 0` — and the *only* code path that ever makes that true is `TransactionTemplate::runInTransaction()`'s own `beginTransaction()` call, a few lines above.

Chain those two facts together and the conclusion is unavoidable: **a method with no `#[Transactional]` never gets its transaction level raised, so `save()` never tracks the aggregate, so there is nothing for `dispatchAfterCommit()` to drain even if it were somehow called.** `OpenWalletHandler`'s own docblock states this as the reason the attribute is there at all, not decoration:

<!-- source: samples/lumen/src/Application/Command/OpenWalletHandler.php -->
```php
 * #[Transactional] is LOAD-BEARING, not cosmetic: DefaultCommandBus opens no transaction of its own, and
 * EloquentRepository::save() only tracks the aggregate when transactionLevel() > 0. The generated transactional proxy
 * installs the TransactionTemplate that is the sole caller of DomainEventDispatcher::dispatchAfterCommit(), so without
 * this attribute the WalletOpened domain event would never publish and S5's ledger projector would never fire.
```

Recall from Chapter 7 that `DefaultCommandBus::send()` opens **no** transaction of its own — it correlates, validates, authorizes, and invokes the handler, full stop. Every bit of transactional behaviour you have seen in `OpenWalletHandler`, `DepositHandler`, `WithdrawHandler`, and `TransferHandler` comes *entirely* from the `#[Transactional]` attribute on their `handle()` methods, through the exact proxy mechanism this chapter just walked through. Strip the attribute from any one of them and the command still "succeeds" — the row still gets written by a plain, unproxied `save()` call — but `WalletOpened`/`FundsDeposited`/`FundsWithdrawn` are raised into the aggregate's private event buffer and then **silently discarded**, because nothing ever drains that buffer. `LedgerProjector` (Chapter 6) would simply never fire, with no error, no warning, and a perfectly successful-looking HTTP response.

!!! warning "No `#[Transactional]`, no domain-event publish — silently"
    This is not a slow failure or a misleading error message; it is a **complete, silent no-op** for every domain event the handler's aggregate raised. The write commits. The event vanishes. Nothing in the response, the logs, or the database schema tells you it happened — the only way to notice is that a downstream projector or listener you expected to fire never does. Treat `#[Transactional]` on a command handler that touches an aggregate as load-bearing, not stylistic.

---

## The atomic `Transfer`: money can't vanish

Chapter 6 already showed you `TransferHandler`'s full source — one `#[Transactional(propagation: Propagation::REQUIRED)]` boundary wrapping a debit, a save, a credit, and a second save. What Chapter 6 didn't show you is the rigorous proof that the atomicity claim actually holds under a real failure. `samples/lumen/tests/Application/TransferSecurityTest.php` dispatches through the real `CommandBus`/`QueryBus` — the exact ports Chapter 7 introduced — and proves both directions:

<!-- source: samples/lumen/tests/Application/TransferSecurityTest.php -->
```php
it('transfers atomically: money is conserved across debit + credit', function () {
    // …
    $commands = $this->fireflyContext()->get(CommandBus::class);
    // …
    $queries = $this->fireflyContext()->get(QueryBus::class);

    // …
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    // …
    $dst = $commands->send(new OpenWallet('owner-B', Currency::EUR));
    $commands->send(new Deposit($src, 10000));

    $commands->send(new Transfer($src, $dst, 4000));

    // Debit + credit committed as one unit of work: the 10000 that left nowhere reappears split 6000/4000.
    expect($queries->ask(new GetBalance($src)))->toBe(6000);
    expect($queries->ask(new GetBalance($dst)))->toBe(4000);
})->group('lumen');

it('rolls the whole transfer back when the credit leg fails (money cannot vanish)', function () {
    // …
    $commands = $this->fireflyContext()->get(CommandBus::class);
    // …
    $queries = $this->fireflyContext()->get(QueryBus::class);

    // Destination in a DIFFERENT currency: the debited EUR amount cannot be credited into a USD wallet, so the
    // credit leg throws currency-mismatch AFTER the debit already ran -> the whole #[Transactional] tx rolls back.
    // …
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    // …
    $dst = $commands->send(new OpenWallet('owner-B', Currency::USD));
    $commands->send(new Deposit($src, 10000));

    expect(fn () => $commands->send(new Transfer($src, $dst, 4000)))
        ->toThrow(CommandProcessingException::class);

    // Load-bearing, non-tautological proof that money cannot vanish: the source debit was ROLLED BACK (still 10000,
    // not 6000) and the destination never received anything (still 0). No value was created or destroyed.
    expect($queries->ask(new GetBalance($src)))->toBe(10000);
    expect($queries->ask(new GetBalance($dst)))->toBe(0);
})->group('lumen');
```

The second test is the one that matters. `Wallet::withdraw()` on the source ran successfully and raised `FundsWithdrawn` into its own buffer; then `Wallet::deposit()` on the destination threw a currency-mismatch `ConflictException` — *after* the debit's `save()` call had already executed inside the same, still-open transaction. Because `#[Transactional]`'s default `rollbackFor` catches every `Throwable`, the entire method rolls back: the source's debit is undone at the database level, and — because rollback happens *before* `dispatchAfterCommit()` would ever be reached on the success path, and `TransactionTemplate` also calls it on the `catch` arm precisely so a rolled-back drain still empties the tracker without ever scheduling a publish — neither `FundsWithdrawn` nor a `FundsDeposited` that never even got raised reaches any listener. `Chapter 7`'s `CommandProcessingException` wraps the underlying `ConflictException`, and the two balance assertions are the whole point: not "the transfer failed" in the abstract, but the source's own `10000` came back **exactly**, and the destination's `0` never moved. No value was created; none was destroyed.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `#[Transactional]` | Class-level default, method-level *replacement* (not merge); `rollbackFor = [Throwable::class]` by default |
| `Propagation` (7 modes) | `TransactionTemplate::execute()` is the one source of truth for all seven, proxy and programmatic caller alike |
| `Isolation` / `readOnly` | Best-effort `SET TRANSACTION …` statements on the outermost transaction only |
| `shouldRollBack()` | `noRollbackFor` wins over `rollbackFor`; neither list matching also commits |
| The generated proxy | `{Target}__FireflyTransactionalProxy extends {Target}`; routes each call through `TransactionInterceptor::run()` then `parent::` |
| Self-invocation bypass | `$this->other()` inside the proxied class skips the interceptor entirely — use the injected `TransactionTemplate` instead |
| `DataAutoConfiguration::transactionalManifest()`/`proxyPlan()` | Compiled artifact first, in-process scan second, empty last — the reason no application needs to bind a manifest by hand |
| The key lesson | `TransactionTemplate` is the **sole** caller of `dispatchAfterCommit()` — no `#[Transactional]`, no domain-event publish, silently |
| `Transfer` | Debit + credit + both `save()`s in one boundary — a failed credit leg rolls back the already-run debit too |

---

## Try it yourself {.exercises}

1. **Reproduce the silent event loss.** In a scratch copy of the project (not the shipped `samples/lumen` package), remove `#[Transactional]` from a copy of `DepositHandler::handle()`, deposit into a wallet through the HTTP API, and confirm the balance *does* update (the row still gets written) while the ledger (`LedgerProjector`'s `ledger_entries` table) gets **no new row at all** — with no error anywhere.
2. **Prove `NOT_SUPPORTED` cannot suspend.** Give a method `#[Transactional(propagation: Propagation::NOT_SUPPORTED)]`, call it from inside another `#[Transactional(propagation: Propagation::REQUIRED)]` method on the *same* connection (through `TransactionTemplate`, not self-invocation), and confirm — per this chapter's "Known-latent" description — that the inner work still runs inside the outer transaction rather than truly outside one.
3. **Read the generated proxy source.** After running `php artisan firefly:cache` in a project with a `#[Transactional]` class, open the emitted file under `bootstrap/cache/firefly/proxies/` and find the four kinds of member `ProxyClassGenerator` renders: the `(new \Firefly\Data\Proxy\MethodInvocation(...))->proceed()` override it writes for every advised method, the private `$__fireflyTxInterceptor` property, the private static `__fireflyTxDescriptor('m')` factory the descriptor literal is baked into, and the public static `__fireflyAdvice()` table `ProxyFactory` reads to know which interceptor bean belongs in which property. Then give a *second* bean a `#[PreAuthorize]` rule and **no** `#[Transactional]` at all, re-run `firefly:cache`, and confirm the plan named it too: it gets a proxy of its own, carrying `$__fireflySecurityInterceptor` and `__fireflySecurityDescriptor('m')` instead. Finally put both advices on one method and read the chain order straight off the generated source — the override's interceptor array is `[$this->__fireflySecurityInterceptor, $this->__fireflyTxInterceptor]`, outermost first, exactly the order Figure 9.1 draws.

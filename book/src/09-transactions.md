<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 9</span>

# Transactions and the `#[Transactional]` Proxy {.chtitle}

By the end of this chapter you will know exactly what `#[Transactional]` does — its seven propagation modes, its isolation/read-only/rollback settings, and the generated proxy that gives it teeth — how self-invocation bypasses that proxy (and what to do instead), how `firefly:cache` closes the loop Chapter 2 opened with `CachedTransactionalConfiguration`, and the single most consequential fact this whole book has been building toward: **a domain event publishes only because `TransactionTemplate` — the machinery behind `#[Transactional]` — is the sole caller of the after-commit dispatch.** No `#[Transactional]`, no publish, no matter how correctly an aggregate raised its event.

!!! note "New term: declarative transaction demarcation"
    Instead of writing `DB::beginTransaction()` / `DB::commit()` / `DB::rollBack()` by hand inside a method body, you *declare* the boundary with an attribute and let a generated proxy enforce it. This is Spring's `@Transactional` model, and it is why Chapters 6 and 7 could already show you `#[Transactional]` on `OpenWalletHandler`, `DepositHandler`, `WithdrawHandler`, and `TransferHandler` without a single explicit `DB::` call inside any of their `handle()` bodies.

---

## The `#[Transactional]` attribute

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
}
```

`TransactionTemplate::execute()` is the single source of truth both the generated proxy and any direct, programmatic caller run through — there is no second code path to keep in sync:

```php
final class TransactionTemplate
{
    public function execute(Closure $work, ?TransactionalDescriptor $descriptor = null): mixed
    {
        $d = $descriptor ?? new TransactionalDescriptor;
        $connection = DB::connection($d->connection);
        $active = $connection->transactionLevel() > 0;

        return match ($d->propagation) {
            Propagation::MANDATORY => $active ? $work() : throw new TransactionRequiredException,
            Propagation::NEVER => $active ? throw new TransactionNotAllowedException : $work(),
            Propagation::SUPPORTS, Propagation::NOT_SUPPORTED => $work(),
            Propagation::REQUIRED => $active ? $work() : $this->runInTransaction($connection, $work, $d, true),
            Propagation::REQUIRES_NEW, Propagation::NESTED => $this->runInTransaction($connection, $work, $d, ! $active),
        };
    }
}
```

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

```php
it('unwinds a NESTED inner rollback to a savepoint, leaving the outer row intact', function () {
    accountService($this->app())->outerWithNested();

    expect(DB::table('accounts')->pluck('name')->all())->toBe(['outer']);
});
```

The `'inner'` row vanishes with the savepoint; `'outer'` — inserted before the nested unit of work even began — survives and later commits normally when the outer method returns.

---

## Isolation, read-only, and the rollback decision

`Isolation` is a string-backed enum whose value **is** the SQL clause:

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

```php
final class TransactionTemplate
{
    private function shouldRollBack(Throwable $e, TransactionalDescriptor $d): bool
    {
        foreach ($d->noRollbackFor as $type) {
            if ($e instanceof $type) {
                return false; // noRollbackFor wins: commit-and-rethrow
            }
        }

        foreach ($d->rollbackFor as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false; // not listed in rollbackFor: commit-and-rethrow
    }
}
```

Read in order: an exception matching `noRollbackFor` always **commits** (checked first, so it wins even over a matching `rollbackFor`); otherwise, a match in `rollbackFor` (the default is everything) **rolls back**; otherwise — only reachable with a deliberately narrowed `rollbackFor` — it **commits**. `AccountService::logButKeep()` exercises exactly the first branch, and a real capstone test proves the row survives the exception it's thrown alongside:

```php
it('commits despite a method-level noRollbackFor exception (override beats class-level)', function () {
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

```php
final class TransferService__FireflyTransactionalProxy extends TransferService
{
    public function transfer(int $amount): int
    {
        return $this->__fireflyTxInterceptor->run(
            fn () => parent::transfer($amount),
            self::__fireflyTxDescriptor('transfer'),
        );
    }
}
```

— routing the real call through `TransactionInterceptor::run()` (which delegates straight to `TransactionTemplate::execute()`) before falling through to `parent::`. A real capstone test confirms the swap actually happened — the resolved bean's class is **not** the plain service class at all:

```php
it('proxies the #[Service] and rolls back BOTH inserts when the method throws', function () {
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

`$service::class` is the generated `AccountService__FireflyTransactionalProxy`, yet `$service instanceof AccountService` is still true — the proxy *is-a* `{Target}`, so every container call and `#[PreDestroy]` resolve against it exactly as they would the original bean. `ProxyFactory` instantiates it **state-preservingly**: `newInstanceWithoutConstructor()` (so `#[PostConstruct]` is not re-run), then a bound closure copies the real bean's scope-visible state via `get_object_vars()` — never `ReflectionProperty` — onto the proxy.

!!! warning "Self-invocation bypasses the proxy"
    A method calling `$this->otherMethod()` from *inside* the proxied class calls straight through `parent::`, skipping `__fireflyTxInterceptor` entirely — the same well-known Spring limitation. This is exactly why `AccountService::outerWithNested()` above doesn't just call some hypothetical `$this->innerNested()` method — it goes through the **injected `TransactionTemplate`** instead, which is the correct escape hatch for getting transactional semantics on an inner unit of work from within another method on the same instance.

---

## Closing the loop from Chapter 2: how a real app actually gets a working proxy

Chapter 2 showed you `App\Support\CachedTransactionalConfiguration` — a real file the `firefly/skeleton` project ships out of the box — and promised you would not need `#[Transactional]` itself "until a later chapter." This is that chapter, and here is exactly why that file exists.

`php artisan firefly:cache` emits **two** separate artifacts for `#[Transactional]`: it runs `TransactionalScanner`/`TransactionalManifestCompiler` — one of the twelve scanner/compiler pairs — to write the manifest data to `bootstrap/cache/firefly/transactional.php`, *and* it runs `ProxyClassGenerator` to emit one `{Target}__FireflyTransactionalProxy` source file per scanned class into a `proxies/` directory, plus a `proxies.php` classmap. `FireflyCacheServiceProvider` registers an autoloader for that classmap unconditionally, so every generated proxy class is loadable the moment the container asks for it.

That handles the **proxy classes**. It does *not*, by itself, make `TransactionalBeanPostProcessor` actually swap anything — for that, the container needs the compiled `TransactionalManifest` *data* bound as a bean, and this is where `TransactionalManifest` behaves differently from every other manifest this book has shown you. `HandlerManifest` (Chapter 7), `EventListenerManifest` (Chapter 8), and `SecurityMethodManifest` (Chapter 10) are all bound through a plain `bound()`-guarded provider default — `$app->instance()` from `FireflyCacheServiceProvider` overrides that unconditionally, regardless of registration order. `TransactionalManifest`'s empty default, by contrast, is a genuine `#[Configuration]` `#[Bean]` on `DataAutoConfiguration`, gated `#[ConditionalOnMissingBean(TransactionalManifest::class)]` — and that condition is evaluated against the **bean definition registry**, not against Laravel container bindings. A plain `$app->instance(TransactionalManifest::class, ...)` call is *invisible* to it: the empty default's own `#[Bean]` still fires later and overwrites whatever was instance-bound.

The only real override seam is a **competing bean definition** — another `#[Configuration]` class supplying its own `#[Bean] transactionalManifest(): TransactionalManifest`, registered at an `#[Order]` below `DataAutoConfiguration`'s `1000`. That is the entire contents of the file Chapter 2 already showed you:

```php
#[Configuration]
final class CachedTransactionalConfiguration
{
    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        $file = base_path('bootstrap/cache/firefly/transactional.php');

        return is_file($file) ? TransactionalManifest::load($file) : new TransactionalManifest([], []);
    }
}
```

Because `firefly/skeleton` ships this file already, in `app/Support/`, discovered by the ordinary component scan like any other `#[Configuration]`, a LaraFly application built the way the Quick Start had you build one gets a **fully working** `#[Transactional]` proxy the moment you run `firefly:cache` — no extra step, no manual wiring. The framework's own test suites, which do not boot through the skeleton, supply the equivalent inline instead: `packages/data/tests/Fixtures/Capstone/CapstoneTransactionalConfiguration` for the `AccountService` fixture above, and `samples/lumen/tests/Support/LumenTransactionalConfiguration` for every handler this book has shown you from `samples/lumen`. All three are the same shape for the same reason.

---

## The key lesson: domain events publish only through a `#[Transactional]` boundary

Everything in this section has been building to one fact, and it is the single most important thing this chapter teaches. Look again at where `DomainEventDispatcher::dispatchAfterCommit()` is actually called from inside `TransactionTemplate`:

```php
final class TransactionTemplate
{
    private function runInTransaction(Connection $connection, Closure $work, TransactionalDescriptor $d, bool $outermost): mixed
    {
        if ($outermost) {
            $this->applySessionSettings($connection, $d);
        }

        $connection->beginTransaction();

        try {
            $result = $work();
        } catch (Throwable $e) {
            if ($outermost) {
                $this->dispatcher?->dispatchAfterCommit($d->connection);
            }

            if ($this->shouldRollBack($e, $d)) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }

            throw $e;
        }

        if ($outermost) {
            $this->dispatcher?->dispatchAfterCommit($d->connection);
        }

        $connection->commit();

        return $result;
    }
}
```

`dispatchAfterCommit()` is called from exactly **two** places, and both are inside `TransactionTemplate` — there is no third call site anywhere in the framework. And `dispatchAfterCommit()` itself only has anything to dispatch because of a second, equally load-bearing fact: `EloquentRepository::save()` (Chapter 5) only registers an entity with `AggregateTracker` when `$connection->transactionLevel() > 0` — and the *only* code path that ever makes that true is `TransactionTemplate::runInTransaction()`'s own `beginTransaction()` call, a few lines above.

Chain those two facts together and the conclusion is unavoidable: **a method with no `#[Transactional]` never gets its transaction level raised, so `save()` never tracks the aggregate, so there is nothing for `dispatchAfterCommit()` to drain even if it were somehow called.** `OpenWalletHandler`'s own docblock states this as the reason the attribute is there at all, not decoration:

```php
/**
 * #[Transactional] is LOAD-BEARING, not cosmetic: DefaultCommandBus opens no transaction of its own, and
 * EloquentRepository::save() only tracks the aggregate when transactionLevel() > 0. The generated transactional
 * proxy installs the TransactionTemplate that is the sole caller of DomainEventDispatcher::dispatchAfterCommit(),
 * so without this attribute the WalletOpened domain event would never publish and S5's ledger projector would
 * never fire.
 */
```

Recall from Chapter 7 that `DefaultCommandBus::send()` opens **no** transaction of its own — it correlates, validates, authorizes, and invokes the handler, full stop. Every bit of transactional behaviour you have seen in `OpenWalletHandler`, `DepositHandler`, `WithdrawHandler`, and `TransferHandler` comes *entirely* from the `#[Transactional]` attribute on their `handle()` methods, through the exact proxy mechanism this chapter just walked through. Strip the attribute from any one of them and the command still "succeeds" — the row still gets written by a plain, unproxied `save()` call — but `WalletOpened`/`FundsDeposited`/`FundsWithdrawn` are raised into the aggregate's private event buffer and then **silently discarded**, because nothing ever drains that buffer. `LedgerProjector` (Chapter 6) would simply never fire, with no error, no warning, and a perfectly successful-looking HTTP response.

!!! warning "No `#[Transactional]`, no domain-event publish — silently"
    This is not a slow failure or a misleading error message; it is a **complete, silent no-op** for every domain event the handler's aggregate raised. The write commits. The event vanishes. Nothing in the response, the logs, or the database schema tells you it happened — the only way to notice is that a downstream projector or listener you expected to fire never does. Treat `#[Transactional]` on a command handler that touches an aggregate as load-bearing, not stylistic.

---

## The atomic `Transfer`: money can't vanish

Chapter 6 already showed you `TransferHandler`'s full source — one `#[Transactional(propagation: Propagation::REQUIRED)]` boundary wrapping a debit, a save, a credit, and a second save. What Chapter 6 didn't show you is the rigorous proof that the atomicity claim actually holds under a real failure. `samples/lumen/tests/Application/TransferSecurityTest.php` dispatches through the real `CommandBus`/`QueryBus` — the exact ports Chapter 7 introduced — and proves both directions:

```php
it('transfers atomically: money is conserved across debit + credit', function () {
    $commands = $this->fireflyContext()->get(CommandBus::class);
    $queries = $this->fireflyContext()->get(QueryBus::class);

    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    $dst = $commands->send(new OpenWallet('owner-B', Currency::EUR));
    $commands->send(new Deposit($src, 10000));

    $commands->send(new Transfer($src, $dst, 4000));

    // Debit + credit committed as one unit of work: the 10000 that left nowhere reappears split 6000/4000.
    expect($queries->ask(new GetBalance($src)))->toBe(6000);
    expect($queries->ask(new GetBalance($dst)))->toBe(4000);
});

it('rolls the whole transfer back when the credit leg fails (money cannot vanish)', function () {
    $commands = $this->fireflyContext()->get(CommandBus::class);
    $queries = $this->fireflyContext()->get(QueryBus::class);

    // Destination in a DIFFERENT currency: the debited EUR amount cannot be credited into a USD wallet, so the
    // credit leg throws currency-mismatch AFTER the debit already ran -> the whole #[Transactional] tx rolls back.
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    $dst = $commands->send(new OpenWallet('owner-B', Currency::USD));
    $commands->send(new Deposit($src, 10000));

    expect(fn () => $commands->send(new Transfer($src, $dst, 4000)))
        ->toThrow(CommandProcessingException::class);

    // Load-bearing, non-tautological proof that money cannot vanish: the source debit was ROLLED BACK (still
    // 10000, not 6000) and the destination never received anything (still 0). No value was created or destroyed.
    expect($queries->ask(new GetBalance($src)))->toBe(10000);
    expect($queries->ask(new GetBalance($dst)))->toBe(0);
});
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
| `CachedTransactionalConfiguration` | The one competing `#[Bean]` that actually activates the cached `TransactionalManifest` — shipped by the skeleton |
| The key lesson | `TransactionTemplate` is the **sole** caller of `dispatchAfterCommit()` — no `#[Transactional]`, no domain-event publish, silently |
| `Transfer` | Debit + credit + both `save()`s in one boundary — a failed credit leg rolls back the already-run debit too |

---

## Try it yourself {.exercises}

1. **Reproduce the silent event loss.** In a scratch copy of the project (not the shipped `samples/lumen` package), remove `#[Transactional]` from a copy of `DepositHandler::handle()`, deposit into a wallet through the HTTP API, and confirm the balance *does* update (the row still gets written) while the ledger (`LedgerProjector`'s `ledger_entries` table) gets **no new row at all** — with no error anywhere.
2. **Prove `NOT_SUPPORTED` cannot suspend.** Give a method `#[Transactional(propagation: Propagation::NOT_SUPPORTED)]`, call it from inside another `#[Transactional(propagation: Propagation::REQUIRED)]` method on the *same* connection (through `TransactionTemplate`, not self-invocation), and confirm — per this chapter's "Known-latent" description — that the inner work still runs inside the outer transaction rather than truly outside one.
3. **Read the generated proxy source.** After running `php artisan firefly:cache` in a project with a `#[Transactional]` class, open the emitted file under `bootstrap/cache/firefly/proxies/` and match its `__fireflyTxInterceptor->run(...)` call and `__fireflyTxDescriptor()` method back to the two things `ProxyClassGenerator` renders, as this chapter described.

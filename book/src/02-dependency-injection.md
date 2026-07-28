<span class="eyebrow">Part I — Foundations · Chapter 2</span>

# Dependency Injection & Auto-Configuration {.chtitle}

By the end of this chapter you will know all four stereotype attributes, how constructor injection resolves dependencies, how to disambiguate between several implementations of one interface with `#[Primary]` and `#[Qualifier]`, how to control list ordering with `#[Order]`, how to inject configuration and expressions with `#[Value]`, what the three component scopes mean, and — tying all of it together — exactly what happens between `php artisan firefly:cache` and your application serving its first request.

!!! note "New term: container"
    A **dependency-injection container** is an object that knows how to build other objects, and hands you a fully-constructed instance instead of you calling `new` and wiring up its dependencies by hand. LaraFly's container is `Illuminate\Container\Container` — the very same container a plain Laravel application already uses — with one addition: PHP 8 attributes tell it *what* to build, so you rarely call `$container->bind(...)` yourself.

---

## Stereotypes: declaring a bean

A **bean** is any object the container manages on your behalf — built once (by default) and handed out on request. You mark a class as a bean with a **stereotype** attribute. LaraFly ships four:

```php
use Firefly\Container\Attributes\{Component, Configuration, Repository, Service};
```

`#[Component]` is the base attribute; `#[Service]`, `#[Repository]`, and `#[Configuration]` are all specialisations of it — literally, each one `extends Firefly\Container\Attributes\Component` in PHP. A component scan finds every one of them with a single reflection call:

```php
$reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF);
```

`ReflectionAttribute::IS_INSTANCEOF` is what makes this work: it matches `#[Component]` itself *and* every attribute that extends it, so the scanner never has to enumerate stereotypes by name. This is also why `firefly/web`'s `#[RestController]` — the attribute you met on `GreetingController` in the Quick Start — participates in the very same scan: it, too, extends `Component`.

You already met the simplest stereotype in the Quick Start:

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Container\Attributes\Service;

/**
 * A #[Service] stereotype: auto-registered as a singleton bean and resolved through the container, so its
 * GreetingProperties dependency is autowired.
 */
#[Service]
final class GreetingService
{
    public function __construct(private readonly GreetingProperties $properties) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }
}
```

`#[Service]` is the stereotype for application/business logic — the LaraFly counterpart of Spring's `@Service`. There is nothing else to write: no `$this->app->bind(GreetingService::class, ...)` anywhere, no factory closure. The attribute is the whole registration.

### `#[Repository]` on a hexagonal port

`#[Repository]` marks a persistence-facing bean, and it is where stereotypes start to show their real value: disambiguating between an interface and the class that implements it. `samples/lumen` defines the *port* — the interface the domain and application layers depend on — with no framework attribute on it at all, because an interface is never itself a bean:

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

The *adapter* — the class that actually talks to Eloquent — is what carries `#[Repository]`:

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
 * (The real file's docblock explains, in full, a PHP parameter-variance constraint this class must respect
 * because it both `extends EloquentRepository` and `implements WalletRepository` — see the shipped source
 * under `samples/lumen/src/Infrastructure/EloquentWalletRepository.php` for the complete rationale.)
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

Nothing in `samples/lumen` ever binds `WalletRepository` to `EloquentWalletRepository` by hand. When a bean's constructor asks for `WalletRepository`, the container needs to know which *concrete* class to hand it — and this is **interface auto-binding**: `ContainerRegistrar::wireInterfaces()` looks at every scanned component's implemented interfaces and, for each interface with exactly one implementing component (or exactly one marked `#[Primary]`, covered later in this chapter), binds the interface to it automatically.

!!! laravel "Laravel parity"
    In a plain Laravel application you would write `$this->app->bind(WalletRepository::class, EloquentWalletRepository::class);` yourself, usually in a service provider's `register()` method. `#[Repository]` plus interface auto-binding is exactly that binding, generated for you from the fact that only one scanned class implements the interface.

### `#[Configuration]` and `#[Bean]` factory methods

Not everything you need to inject is a class you own. `#[Configuration]` marks a class as a **source of `#[Bean]` factory methods** — each method's return type becomes the bean's registered type, and its parameters are resolved and injected exactly like a constructor's. `#[Configuration]` is itself a `#[Component]`, so the configuration class is a managed bean too.

The skeleton project you scaffolded in the Quick Start already ships one, real, shipped example:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * Category C: the app-side #[Configuration] that firefly:cache generates/ships. It LOADS the app's compiled
 * TransactionalManifest as a bean — the only override seam for it, because binding it directly on the Laravel
 * container is invisible to DataAutoConfiguration's #[ConditionalOnMissingBean] (which consults the
 * BeanDefinitionRegistry). Its default #[Order] 0 sorts strictly before DataAutoConfiguration's #[Order(1000)],
 * so the empty default steps aside. On a cached boot this #[Configuration] is discovered from the compiled
 * component/context manifests (never scanned); until firefly:cache has run, it returns an empty manifest.
 */
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

`transactionalManifest()` returns `TransactionalManifest` — so that is the type the bean is registered under, and any constructor that asks for a `TransactionalManifest` receives whatever this method returns. You will not need `#[Transactional]` itself until a later chapter, but the *shape* — `#[Configuration]` class, `#[Bean]` method, return type as registration key — is one you will see again every time this book introduces a new package that needs to hand you a pre-built object rather than a class you construct directly.

!!! laravel "Laravel parity"
    `#[Configuration]` + `#[Bean]` is the direct counterpart of a Laravel service provider's `register()` method calling `$this->app->singleton(SomeType::class, fn () => ...)` — except the *type* the closure returns is read from the method's own return-type declaration, so there is nothing to keep in sync by hand.

---

## Constructor injection

!!! note "New term: injection"
    **Dependency injection** means a bean receives its collaborators through its constructor rather than constructing them itself or fetching them from a global. The container inspects a bean's constructor, resolves each parameter's type, and passes the built (or already-built) instance in.

You have now seen this twice without a full explanation. `OpenWalletHandler`, the command handler that opens a new wallet in `samples/lumen`, is the clearest example — its whole job is to receive the *port*, not the adapter:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Lumen\Domain\Wallet;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles OpenWallet: mints a new wallet id, opens the aggregate, and persists it. The handled command type is
 * inferred from the sole handle() parameter (HandlerScanner param inference) — no explicit #[CommandHandler(...)].
 */
#[CommandHandler]
class OpenWalletHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(OpenWallet $command): string
    {
        $id = 'wlt-'.bin2hex(random_bytes(8));
        $wallet = Wallet::open($id, $command->ownerId, $command->currency);
        $this->wallets->save($wallet);

        return $id;
    }
}
```

`OpenWalletHandler`'s constructor asks for `WalletRepository` — the *interface*, not `EloquentWalletRepository`. The container resolves that request in three steps: it looks up `WalletRepository` in the compiled manifest, finds the interface auto-bound to `EloquentWalletRepository` (the single scanned implementation), and builds — or reuses — that instance. `OpenWalletHandler` itself never imports `EloquentWalletRepository`, never mentions Eloquent, and would keep compiling unchanged if `samples/lumen` swapped in a different storage adapter tomorrow. That is the entire point of coding against a port: the handler's own source code is proof that it does not know, and does not need to know, what is on the other side of `WalletRepository`.

!!! tip "Tip: constructor injection is the only kind"
    LaraFly does not support property or setter injection — every dependency a bean needs must be declared as a typed constructor parameter. This is deliberate: a class's constructor signature is a complete, honest list of everything it depends on, readable without opening the class body.

::: figure art/figures/di-autoconfig.svg | Figure 2.1 — Component scan to compiled manifest to condition pass to bean registry to eager singletons.

---

## Scopes

Every component is a **singleton** by default: the container builds it once, and every resolution after the first returns the same instance. Choose a different lifetime with the `scope` argument any stereotype attribute accepts:

```php
use Firefly\Container\Attributes\Service;
use Firefly\Container\Scope;

#[Service(scope: Scope::Transient)]  // a new instance every time it is resolved
final class RequestId {}
```

`Scope` is a plain PHP enum with three cases:

```php
enum Scope
{
    case Singleton;  // one instance for the life of the application (the default)
    case Transient;  // a new instance every resolution
    case Scoped;     // one instance per Laravel request/scope
}
```

Use `Scope::Transient` for anything that must never be shared — a per-operation correlation id, a mutable builder. Use the default `Scope::Singleton` for everything else, which is almost everything: services, repositories, and configuration DTOs are all naturally shareable, and a singleton is cheaper to resolve.

!!! warning "Warning"
    `Scope::Scoped` binds one instance per Laravel request under classic PHP-FPM. If you deploy under Octane, a scoped bean's lifetime is one *request*, not one *worker* — do not reach for it as a substitute for `Scope::Singleton` just because it sounds safer; the two have genuinely different lifetimes.

---

## `#[Primary]` and `#[Qualifier]`: disambiguating implementations

Interface auto-binding, described earlier in this chapter, has a simple rule for the common case: exactly one scanned implementation of an interface gets bound to it automatically. But real applications often have *more* than one implementation of the same interface — a production adapter and a stub, or two genuinely different strategies. `#[Primary]` and `#[Qualifier]` are how you tell the container which one you mean:

```php
use Firefly\Container\Attributes\{Primary, Qualifier, Service};

interface Greeter
{
    public function greet(): string;
}

#[Service]
#[Primary]
final class EnglishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'Hello';
    }
}

#[Service('spanish')]
#[Qualifier('spanish')]
final class SpanishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'Hola';
    }
}
```

With both beans registered, the container resolves the interface three different ways depending on what you ask for:

```php
$container->get(Greeter::class);      // EnglishGreeter — the #[Primary] one
$container->getByName('spanish');     // SpanishGreeter — resolved by its bean name
$container->getAll(Greeter::class);   // [EnglishGreeter, SpanishGreeter] — every implementation
```

`#[Primary]` breaks the tie when a constructor asks for the bare interface: the container binds `Greeter::class` to whichever implementation carries `#[Primary]`, exactly the way `wireInterfaces()` bound `WalletRepository` to `EloquentWalletRepository` earlier — except now the choice is explicit instead of being the only option. `#[Qualifier('spanish')]` gives `SpanishGreeter` a *name* you can ask for directly, independent of type resolution, when you specifically need the non-primary one.

!!! warning "Warning"
    With zero or with more than one `#[Primary]` implementation of the same interface, the container has no well-defined default — resolving the bare interface becomes ambiguous. Give every interface with multiple implementations exactly one `#[Primary]`, or resolve every implementation by its `#[Qualifier]` name instead.

### `#[Order]`

`getAll(Greeter::class)` above returns every implementation — and it returns them **sorted by `#[Order]`**, lower first, exactly like Spring's `@Order` convention:

```php
use Firefly\Container\Attributes\Order;

#[Service]
#[Order(10)]
final class EnglishGreeter implements Greeter { /* ... */ }

#[Service]
#[Order(20)]
final class SpanishGreeter implements Greeter { /* ... */ }
```

The default order is `0` when the attribute is omitted, so anything you explicitly order with a positive number sorts after every un-ordered bean, and anything you order negatively sorts before them. `#[Order]` also targets `#[Bean]` factory methods, not just classes — the attribute works identically wherever a list of beans needs a deterministic sequence.

---

## `#[Value]` injection

Sometimes what you need injected is not a bean at all, but a single scalar — a configuration value or a small computed expression. `#[Value]` targets one constructor parameter directly:

```php
use Firefly\Container\Attributes\Value;

final class MailerConfig
{
    public function __construct(
        #[Value('${MAIL_HOST:localhost}')] public readonly string $host,
        #[Value('#{25 * 2}')] public readonly int $port,
    ) {}
}
```

Two expression forms are supported. `${NAME:default}` reads environment variable `NAME`, falling back to `default` when it is unset; `#{expr}` evaluates a small, sandboxed expression language — `#{25 * 2}` resolves to the integer `50`. (`firefly/config`, covered in a later chapter, extends the same resolution to read application configuration, not just the environment.)

!!! note "Note"
    `#[Value]` targets exactly one constructor parameter — it is not a class-level stereotype, and a class carrying only `#[Value]`-annotated parameters still needs a stereotype attribute like `#[Service]` if you want the container to manage it as a bean in its own right.

---

## From component scan to zero-reflection boot

Every stereotype, every `#[Bean]`, every `#[Primary]`/`#[Qualifier]`/`#[Order]` you have just read about is *reflection metadata* — inert until something reads it. That something is `ComponentScanner`, and it runs **exactly once**, when you invoke `firefly:cache`, never again on any request the running application serves.

`ComponentScanner::scan()` walks every PSR-4 root your `config/firefly.php` names under `scan.paths`, reflects each class it finds, and — for every class carrying a `#[Component]`-instanceof attribute — records its stereotype, its scope, whether it is `#[Primary]`, its `#[Order]`, its `#[Qualifier]` name, the interfaces it implements, and (for a `#[Configuration]` class) the list of its `#[Bean]` methods. The result is a plain array of `ComponentDescriptor`s — no objects, no closures, nothing that cannot be serialized.

`ManifestCompiler` takes that array and writes it to disk with nothing more exotic than PHP's own `var_export()`:

```php
"<?php\n\ndeclare(strict_types=1);\n\n// Generated by firefly/container. Do not edit.\n\nreturn "
    .var_export($rows, true)
    .";\n";
```

The result — `bootstrap/cache/firefly/component.php` in your project — is an ordinary, `require`-able PHP file: a literal array, with no reflection, no attribute parsing, and no filesystem walk needed to read it back. `ContainerRegistrar::register()` loads that file and does the actual container wiring described throughout this chapter — binding each component under its own class, wiring interfaces (including the `#[Primary]`/sole-implementation rule), and registering every `#[Bean]` factory's output under its return type.

`php artisan firefly:cache` is what drives this whole pipeline for your application:

```bash
php artisan firefly:cache
```

```
firefly:cache — wrote 8 manifest(s) + 0 proxy(ies) to /path/to/my-app/bootstrap/cache/firefly
```

You already ran this once, indirectly — `composer create-project firefly/skeleton` calls it for you in its `post-create-project-cmd` script, which is why the Quick Start's application booted correctly without you ever running the command by hand. From here on, any time you add or change a `#[Service]`, `#[Repository]`, `#[Configuration]`, or any other Firefly attribute, re-run `firefly:cache` to recompile the manifest; `php artisan firefly:clear` deletes the compiled cache and falls back to the slower, reflection-based development scan.

!!! tip "Tip: `firefly:cache` is not just for containers"
    The same command also compiles the manifests for every other pillar Chapter 1 introduced — routes, transactional proxies, CQRS handlers, event listeners, security rules, and more. Dependency injection is simply the first and most foundational one; the same "reflect once, freeze into a manifest, boot from the frozen copy" idea repeats for every one of them.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `#[Component]` / `#[Service]` / `#[Repository]` / `#[Configuration]` | Mark a class as a managed bean; the first three specialise the base attribute |
| Constructor injection | The container resolves and passes each typed constructor parameter automatically |
| `Scope::Singleton` / `Transient` / `Scoped` | One shared instance (default), a fresh instance per resolution, or one per request |
| `#[Primary]` | Breaks ties when several beans implement the same interface |
| `#[Qualifier]` | Names a specific bean for lookup independent of type resolution |
| `#[Order]` | Sorts a `getAll()` list of implementations, lower first |
| `#[Bean]` on a `#[Configuration]` method | Registers the method's return value under its return type |
| `#[Value]` | Injects a resolved environment value or expression into one constructor parameter |
| `firefly:cache` | Runs the component scan once and compiles it to a zero-reflection manifest |

---

## Try it yourself {.exercises}

1. **Add a second implementation.** Give `Lumen\Infrastructure\WalletRepository` a second, in-memory implementation of your own (in a scratch project — do not modify the shipped `samples/lumen` package) and mark it `#[Repository]` without `#[Primary]`. Run `firefly:cache` and observe what happens to interface auto-binding now that two implementations exist.
2. **Trace a constructor.** Open `OpenWalletHandler` again and, without looking anything up, write down every bean the container must resolve — directly or transitively — to build one instance of it.
3. **Read the compiled manifest.** After running `php artisan firefly:cache` in a skeleton app, open `bootstrap/cache/firefly/component.php` and find the entry for `App\GreetingService`. Match each array key back to a `ComponentDescriptor` field this chapter described.

# Application Context

`firefly/context` is LaraFly's boot engine — Spring's `ApplicationContext`, ported. It runs one
deterministic pipeline, the `FireflyKernel`, that turns component definitions from `firefly/container`
(plus, later, auto-configurations) into a fully wired, running application: conditions are evaluated,
`BeanPostProcessor`s and `#[PostConstruct]`/`#[PreDestroy]` callbacks run, `#[AsEventListener]`s are
registered, infrastructure starts, and eager singletons resolve — all in an order every other package
can rely on without knowing about each other.

## The boot pipeline

`FireflyKernel` is the **only** source of ordering. It never decides *what* runs — packages and your
application contribute `BootPass` instances via `FireflyKernel::addPass()` — but it alone decides
*when* each contributed pass runs, sorted by a deterministic total order: `(phase, order(), FQCN)`.
The kernel is never edited to add a new capability; a later package always extends the pipeline
through `addPass()`.

```php
use Firefly\Context\Boot\{BootContext, BootPass, BootPhase};

final class WarmReportCachePass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0; // tie-break within the phase — lower runs first, the #[Order] convention
    }

    public function run(BootContext $context): void
    {
        // $context->container   — the underlying Illuminate\Container\Container
        // $context->definitions — the BeanDefinitionRegistry (definition stage only)
        // $context->config      — firefly/config's Config accessor
        // $context->profiles    — active Profiles
        $context->container->make(ReportCache::class)->warm();
    }
}
```

A package contributes its passes by extending `FireflyServiceProvider` and overriding `passes()` —
never by touching `FireflyKernel` itself:

```php
use Firefly\Context\Boot\FireflyServiceProvider;

final class ReportingServiceProvider extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [new WarmReportCachePass];
    }
}
```

`FireflyServiceProvider::register()` fetches the shared `FireflyKernel` from the container, calls
`addPass()` for every pass you return, and wires two callbacks — one on Laravel's `booting()`, one on
`booted()` — that run the kernel's definition-stage and instance-stage phases respectively.
`FireflyKernel::run()` is idempotent per phase, so every `FireflyServiceProvider` subclass can safely
register the same two callbacks: only the first one to fire actually runs each phase's passes.

The kernel deliberately does **not** derive ordering from the order Laravel registers service
providers in — Laravel registers auto-discovered (package) providers *before* application providers,
the inverse of what a predictable pipeline needs. Deriving order from provider registration would make
boot order silently depend on that accident.

### `BootPhase`

Ordinals are gapped so a later milestone can slot a new phase between two existing ones without
renumbering. Phases up to `FlushDefinitions` are the **definition stage** — pure `BeanDefinitionRegistry`
data, no container writes, run from Laravel's `booting()`; everything after is the **instance stage** —
operates on resolved objects, run from `booted()`. That split guarantees every provider's own `boot()`
method sees a fully-wired Firefly container, regardless of which provider Laravel happens to construct
first.

| Ordinal | Phase | What happens |
|---|---|---|
| 100 | `ConfigAndProfiles` | `firefly/config` resolves active profiles and configuration. |
| 200 | `AutoConfigDiscovery` | *(seam reserved for a later milestone)* discover auto-configuration candidates only — must not add definitions here. |
| 300 | `UserConfigurations` | Your `#[Configuration]`/`#[Bean]` definitions enter the `BeanDefinitionRegistry`, tagged `DefinitionSource::User`. |
| 400 | `ConditionPassOne` | Evaluate registry-independent conditions over **user** definitions (Spring's `PARSE_CONFIGURATION`). |
| 500 | `AutoConfigurations` | *(seam reserved for a later milestone)* auto-configuration definitions enter the registry, tagged `DefinitionSource::AutoConfiguration`. |
| 600 | `ConditionPassTwo` | Evaluate **every** condition — including bean conditions — over **auto-configuration** definitions (Spring's `REGISTER_BEAN`), incrementally. |
| 650 | `FlushDefinitions` | The single `ContainerRegistrar::register()` write: builds one condition-filtered manifest and hands it to `firefly/container`. |
| 700 | `BeanPostProcessors` | Installs one composite `BeanPostProcessor` extender per abstract. |
| 800 | `EventListeners` | Registers every `#[AsEventListener]` method against Laravel's event dispatcher. |
| 850 | `InfrastructureStart` | Resolves and `start()`s every `Firefly\Kernel\Lifecycle` component, fail-fast, `#[Order]`-sorted. |
| 900 | `EagerSingletons` | Resolves every non-`#[Lazy]` singleton and `#[Bean]` factory, `#[Order]`-sorted from the manifest. |
| 1000 | `WiringPasses` | Seam reserved for later milestones' own wiring passes. |
| 1200 | `ContextRefreshed` | Fires `ContextRefreshedEvent`, then `ApplicationReadyEvent`. |

Each condition pass runs strictly **after** the definitions it filters enter the registry
(`UserConfigurations` < `ConditionPassOne`; `AutoConfigurations` < `ConditionPassTwo`) — a condition
pass placed earlier would evaluate against an empty or partial registry, and every condition would
vacuously "pass" no matter what it actually says.

As of this milestone, phases 200 and 500 are reserved seams with no contributed pass yet — a later
milestone's starter mechanism populates them. `firefly/context` ships the enforcement rules and the
two-pass evaluator ready for that mechanism to plug into (see below).

Phase 300 itself is not fully wired yet either, and this is worth being just as honest about:
`UserConfigurationsPass` does not scan `#[Configuration]`/`#[Bean]` classes itself — it accepts an
already-assembled `list<BeanDefinition>` via constructor injection (empty by default), the same
"accept pre-scanned data, do not fake a scan" pattern used throughout this milestone. In a real
application today, nothing automatically bridges `ComponentScanner`'s and `ContextScanner`'s output
into that list — a caller must build it by hand (see `IntegrationTest`'s `integrationUserDefinitions()`
for the exact shape). Until a later milestone wires that bridge, phase 300 adds nothing on its own.
The same is true of `FlushDefinitionsPass`'s `ConfigPropertiesManifest` parameter — M3's
`#[ConfigProperties]` scanner is not wired into the boot pipeline either.

## Conditional registration

Six attributes gate whether a component or a single `#[Bean]` method survives into the running
application. Each is `INSTANCEOF`-partitioned into one of two evaluation passes, never matched by name:

**Pass one — registry-independent** (`ConditionAttribute`, evaluated over config/classpath/profiles):

```php
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

#[Component]
#[ConditionalOnProperty(name: 'firefly.feature.newBilling')]
final class NewBillingGateway {}
```

```php
use Firefly\Context\Condition\Attributes\{ConditionalOnClass, ConditionalOnMissingClass, ConditionalOnProfile};

#[Component]
#[ConditionalOnClass('Redis')]           // present on the classpath (class_exists() || interface_exists())
final class RedisCacheAdapter {}

#[Component]
#[ConditionalOnMissingClass('Redis')]    // the inverse
final class ArrayCacheAdapter {}

#[Component]
#[ConditionalOnProfile('local', 'testing')] // any listed profile active
final class InMemoryMailer {}
```

`#[ConditionalOnProperty(name, havingValue: null, matchIfMissing: false)]`: with no `havingValue`, the
property must be present and truthy; a present-but-`null` value counts as **missing** (the same rule
`firefly/config`'s `Config::required()` applies), so `matchIfMissing` decides the outcome.

**Pass two — bean conditions** (`BeanConditionAttribute`, evaluated against the `BeanDefinitionRegistry`,
never against resolved instances — matching Spring):

```php
use Firefly\Context\Condition\Attributes\{ConditionalOnBean, ConditionalOnMissingBean};

interface CachePort {}

#[Component]
#[ConditionalOnMissingBean(CachePort::class)]
final class InMemoryCacheAutoConfig implements CachePort {}
```

### Gating a single `#[Bean]` method

Either kind of attribute may also be placed on ONE `#[Bean]` method rather than on the
`#[Configuration]` class itself. A method-level condition failing removes only that method from the
`beans` `firefly/container` sees for the class — never the whole definition, and never any other
`#[Bean]` method declared alongside it:

```php
use Firefly\Container\Attributes\{Bean, Configuration};
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

#[Configuration]
final class CacheAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(CachePort::class)]
    public function defaultCache(): CachePort
    {
        return new InMemoryCacheAutoConfig;
    }

    #[Bean]
    public function cacheWarmer(): CacheWarmer // NOT gated — always registers regardless of defaultCache()'s outcome
    {
        return new CacheWarmer;
    }
}
```

### The user-component rule

**Bean conditions are only valid on auto-configurations.** Attaching `#[ConditionalOnBean]` or
`#[ConditionalOnMissingBean]` to an ordinary user component throws `Firefly\Kernel\Exception\Framework\ConfigurationException`
the moment its conditions are evaluated:

```
#[ConditionalOnMissingBean] is not supported on user component [App\Foo] — bean conditions
are only valid on auto-configurations, which run after all user beans. Use #[ConditionalOnProperty]
or #[ConditionalOnClass] instead.
```

Why: every user component is registered in the *same* phase (`UserConfigurations`, 300). Between two
user components, "does bean X already exist?" has no deterministic answer — it would depend on
filesystem scan order, which is exactly the hazard Spring's own documentation calls out for
`@ConditionalOnBean`. Auto-configurations run strictly *after* all user definitions (evaluated in
`ConditionPassTwo`, 600, incrementally in a fixed `(order, FQCN)` sequence), so for them the question
always has a well-defined, reproducible answer. LaraFly does not merely document this hazard the way
Spring does — it rejects it outright, at evaluation time, so the bug can never ship silently.

Because phases 200/500 (auto-configuration discovery/registration) are reserved seams with no
contributed pass yet, `DefinitionSource::AutoConfiguration` definitions are not yet reachable through
the standard `#[Component]` scan — only a caller that constructs a `BeanDefinition` directly with
`source: DefinitionSource::AutoConfiguration` can use bean conditions today, whether the condition is
attached to the class or to one of its `#[Bean]` methods. The rule above is fully implemented and
enforced now — at both granularities — so that a later milestone's starter mechanism has a safe,
tested seam to build on.

## `BeanPostProcessor`

Every bean — component or `#[Bean]` factory output — passes through the same two-pass pipeline as it
initializes:

```php
interface BeanPostProcessor
{
    public function beforeInitialization(object $bean, string $declaredClass): object;

    public function afterInitialization(object $bean, string $declaredClass): object;
}
```

Per bean, in order:

1. every `BeanPostProcessor::beforeInitialization()`, `#[Order]`-ascending;
2. the bean's own `#[PostConstruct]` method(s);
3. every `BeanPostProcessor::afterInitialization()`, `#[Order]`-ascending.

`#[Order]` on the processor's own class sets its position in that sequence (lower runs first, the same
convention `getAll()` uses). The ordered processor list is frozen once, from the compiled manifest,
before any processor is resolved — never re-derived from resolved instances, so a processor that is
itself proxied by another processor can't silently sort to position zero.

### The proxy contract

Either pass method may return a **different** object than the one it received — that substitution is
the seam a later milestone (`#[Transactional]`, `#[PreAuthorize]`) uses to swap in a proxy. Two rules
are load-bearing:

- **Only create a proxy from `afterInitialization()`.** By the time it runs, `#[PostConstruct]` has
  already fired against the real, pre-proxy instance; replacing the bean earlier, in
  `beforeInitialization()`, would make `#[PostConstruct]` run against the substitute instead.
- **A proxy MUST `extend` the declared class — never wrap it via `__call()`.** Lifecycle callbacks
  (`#[PostConstruct]`/`#[PreDestroy]`) are invoked through `$container->call([$bean, $method])`, which
  reflects on the object to resolve the method and inject its parameters. `ReflectionMethod` cannot see
  a method that exists only via `__call()` magic, so a composition-style wrapper throws
  `ReflectionException` the first time a lifecycle callback runs against it. Extending the class keeps
  every declared method — including any future `#[PreDestroy]` — reflectable exactly as it would be on
  the plain instance.

```php
use Firefly\Container\Attributes\Component;
use Firefly\Context\Lifecycle\PostConstruct;

#[Component]
class ReportGenerator                       // NOT final — the proxy below must extend it
{
    #[PostConstruct]
    public function warm(): void { /* ... */ }
}
```

```php
use Firefly\Container\Attributes\{Component, Order};
use Firefly\Context\Processor\BeanPostProcessor;

#[Component]
#[Order(10)]
final class ReportCachingProcessor implements BeanPostProcessor
{
    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        if ($declaredClass !== ReportGenerator::class) {
            return $bean;
        }

        /** @var ReportGenerator $bean */
        return new CachingReportGenerator($bean); // extends ReportGenerator — never composes it
    }
}

final class CachingReportGenerator extends ReportGenerator
{
    public function __construct(private readonly ReportGenerator $inner) {}
}
```

`$declaredClass` is always the class recorded on the bean's *definition* (the manifest's declared
class), threaded through by the caller — never `$bean::class`. Once a bean has been replaced by a
proxy, `$bean::class` is the proxy's class, which has no entry in the component manifest.

This is about `BeanPostProcessor`'s own contract, though — it is a *separate* question from how
`#[PostConstruct]`/`#[PreDestroy]` are looked up internally. For the canonical hexagonal shape
`#[Bean] fn(): SomePort`, the manifest's declared class IS the interface `SomePort` — and
`ContextScanner` never scans an interface, so there is no lifecycle entry to find under that key.
Internally, the engine captures the bean's *concrete* class once, at the moment it is built —
guaranteed non-proxy, since a proxy can only be created from `afterInitialization()`, which has not
run yet — and uses that for the `#[PostConstruct]`/`#[PreDestroy]` lookup instead, carrying it
forward into `DisposableBeanRegistry` so `#[PreDestroy]` still resolves at context close. The net
effect: a `#[Bean]` method returning an interface is lifecycle-managed exactly like one returning a
concrete class — you do not need to do anything differently for either shape.

## Lifecycle

`#[PostConstruct]` and `#[PreDestroy]` mark methods run once at initialization and once at teardown —
Spring's annotations of the same name, ported as inert metadata: the attributes themselves carry no
logic, `InitDestroyInvoker` supplies all discovery and dispatch.

```php
use Firefly\Container\Attributes\Component;
use Firefly\Context\Lifecycle\{PostConstruct, PreDestroy};

#[Component]
final class ReportGenerator
{
    #[PostConstruct]
    public function warm(Cache $cache): void
    {
        // $cache is resolved and injected — invocation goes through $container->call(),
        // never $bean->warm() directly, so init/destroy methods get the same parameter
        // injection as a #[Bean] factory method.
    }

    #[PreDestroy]
    public function flush(): void
    {
        // runs at ApplicationContext::close()
    }
}
```

Destruction runs in **reverse** order at every level:

- multiple `#[PreDestroy]` methods on the *same* class run in reverse declaration order relative to
  how their `#[PostConstruct]` counterparts ran;
- across beans, `ApplicationContext::close()` drains the tracked singletons in reverse *registration*
  order — the bean that was resolved (and so registered) last is destroyed first, since it is more
  likely to depend on beans registered earlier.

Singleton `#[PreDestroy]` callbacks are tracked via `WeakReference` in a `DisposableBeanRegistry` and
drained once, at context close; `Scope::Scoped` beans are tracked separately and drained per request
(see Octane, below). `Scope::Transient` beans are never tracked — prototype-scoped beans are not
lifecycle-managed.

## Events

Application code publishes and listens for events through one hexagonal port, never through Laravel's
dispatcher directly:

```php
interface ApplicationEventPublisher
{
    public function publish(object $event): void;
}
```

`DispatcherEventPublisher` is the shipped adapter over `illuminate/events`. It resolves the dispatcher
from the container **fresh on every call** rather than caching it — this is what lets `Event::fake()`
(which swaps the `'events'` container binding) intercept publishes made through a publisher instance
constructed before the fake was installed.

```php
use Firefly\Container\Attributes\Component;
use Firefly\Context\Event\{ApplicationReadyEvent, AsEventListener, ContextRefreshedEvent};

#[Component]
final class BootLogger
{
    #[AsEventListener]
    public function onRefreshed(ContextRefreshedEvent $event): void { /* ... */ }

    #[AsEventListener]
    public function onReady(ApplicationReadyEvent $event): void { /* ... */ }
}
```

`#[AsEventListener(event: null, order: 0)]` is repeatable, so one method-per-event or several
`#[AsEventListener]`s on the same method both work. When `$event` is omitted it is inferred from the
listener method's first parameter type — once, at scan time, never at boot; an uninferable signature
throws at scan time rather than reaching a silently-dead listener at runtime.

Every listener is wired through a guard that always returns `null` to the dispatcher regardless of
what your method itself returns: Illuminate's dispatch loop unconditionally stops delivering to every
listener registered after the current one the instant any listener returns exactly `false` — a trivial,
silent accident (e.g. `return $repository->delete($id);`). Firefly's events are notifications, not
filters, so that hazard is closed for every `#[AsEventListener]` automatically.

Three lifecycle events fire over the same port, at the times below. They are deliberately flat
(`final readonly`, no shared base class) — Illuminate's dispatcher matches listeners against an event's
*concrete* class (and its interfaces), never by walking parent classes, so a shared "ApplicationEvent"
base would make listeners registered against it silently never fire.

| Event | Fired | When |
|---|---|---|
| `ContextRefreshedEvent` | `ContextRefreshedPass`, phase 1200 | The context is fully wired. |
| `ApplicationReadyEvent` | `ContextRefreshedPass`, phase 1200, immediately after | The application is ready to serve. |
| `ContextClosedEvent` | `ApplicationContext::close()` | The context is shutting down, before `#[PreDestroy]`/`Lifecycle::stop()` run. |

## Octane

Under Octane, the boot pipeline itself runs **once per worker**, not once per request:
`FireflyKernel::run()` is idempotent per phase, and both its callbacks are registered from
`FireflyServiceProvider::register()` against Laravel's `booting()`/`booted()` hooks, which Octane fires
once when the long-lived worker application boots — not on every request it then serves. Component
scanning, condition evaluation, `BeanPostProcessor` installation, and eager singleton resolution all
happen exactly once per worker process.

What *does* need resetting between requests is per-request state: `Scope::Scoped` bean instances, and
any `#[PreDestroy]` callbacks owed to them. `FireflyServiceProvider` wires an `OctaneListener` (only
when `laravel/octane` is actually installed — it is a dev dependency, not a runtime requirement, since
LaraFly's baseline runtime is PHP-FPM) to Octane's `RequestReceived`, `RequestTerminated`,
`TaskTerminated`, and `TickTerminated` events. Each delegates to `StateResetter::reset($event->sandbox)`
— the **sandbox**, Octane's fresh-per-request container clone, never `$event->app`, the original
long-lived worker application; resetting the wrong one would be a silent no-op on a container nothing
is ever served from.

`StateResetter` first drains `DisposableBeanRegistry`'s scoped ledger — running `#[PreDestroy]` on
every tracked scoped bean, in reverse registration order — and only then calls
`Container::forgetScopedInstances()`, which has no destruction callback of its own and would otherwise
silently drop anything not drained first (a scoped bean holding a database transaction or file handle
would leak it). Resetting on both `RequestReceived` and `RequestTerminated` makes the reset
crash-resilient: a request that dies mid-flight cannot poison the next one, because the next request's
own `RequestReceived` resets before it runs.

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

`register()` does three more things, each guarded so several `FireflyServiceProvider` subclasses
(one per Firefly package) can safely coexist and only the first one actually performs the write:

- Binds the `ApplicationEventPublisher` port (see Events, below) to the shipped
  `DispatcherEventPublisher` adapter, as a singleton.
- After the instance-stage phases finish (inside the `booted()` callback), binds the resulting
  `ApplicationContext` into the container as a singleton — so every resolution returns the *same*
  instance, making `isActive()` and `close()`'s idempotency genuinely true, not merely documented.
- Registers `ApplicationContext::close()` against the runtime's *actual* shutdown hook — so
  `#[PreDestroy]`/`Lifecycle::stop()` run at real application shutdown, not only when something
  remembers to call `close()` by hand — but which hook that is **differs by runtime**, and getting
  this backwards was this milestone's own capstone bug (see "Octane", below, for the full story):
  under PHP-FPM (a *fresh* application per request) Laravel's `$app->terminating()` hook genuinely IS
  application shutdown, so `close()` is wired there. Under Octane the *same* `terminating()` hook
  fires at the end of **every request**, because Octane clones the worker application into a
  per-request sandbox and `Application::terminate()` runs through that clone — so `close()` is wired
  to Octane's `WorkerStopping` event instead, which fires exactly once per worker. The split is
  decided by whether this process is **actually running inside an Octane worker right now** — the
  `LARAVEL_OCTANE` process environment marker Octane's own start commands inject before PHP even
  starts (the same signal, read the same way, Laravel's own framework uses for this exact decision:
  `Illuminate\Foundation\Exceptions\Renderer\Listener::registerListeners()`) — **not** merely whether
  `laravel/octane` is installed: `composer require laravel/octane` puts the package on the classpath
  of every process (`queue:work`, `schedule:run`, artisan commands, this package's own test suite,
  any FPM-served route in a hybrid deploy), and none of those ever start a worker or dispatch
  `WorkerStopping`, so gating on presence alone would silently strand them in the `WorkerStopping`
  branch. Every one of those non-worker processes takes the `terminating()` path, same as plain
  PHP-FPM.

The kernel deliberately does **not** derive ordering from the order Laravel registers service
providers in — Laravel registers auto-discovered (package) providers *before* application providers,
the inverse of what a predictable pipeline needs. Deriving order from provider registration would make
boot order silently depend on that accident.

### `BootPhase`

Ordinals are gapped so a later milestone can slot a new phase between two existing ones without
renumbering. Phases up to `FlushDefinitions` are the **definition stage** — pure `BeanDefinitionRegistry`
data, no container writes *before* `FlushDefinitions` itself, which *is* a container write (the single
`ContainerRegistrar::register()` flush point) — run from Laravel's `booting()`; everything after is the
**instance stage** —
operates on resolved objects, run from `booted()`. That split guarantees every provider's own `boot()`
method sees a fully-wired Firefly container, regardless of which provider Laravel happens to construct
first.

| Ordinal | Phase | What happens |
|---|---|---|
| 100 | `ConfigAndProfiles` | `firefly/config` resolves active profiles and configuration. *(no pass ships for this phase yet — see below.)* |
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

Phase 100 (`ConfigAndProfiles`) deserves the same disclosure, for consistency, even though it is the
smallest gap of the three: no `BootPass` ships for it at all. The row's prose ("`firefly/config`
resolves active profiles and configuration") is substantively true — `Config`/`Profiles` genuinely
are what `BootContext` carries into every other pass — it just does not happen *in this phase*; the
caller constructs both and hands them to `BootContext` directly (see `freshIntegrationBootContext()`
in `IntegrationTest`), the same "accept pre-resolved data" shape as phases 300 and 650's
`ConfigPropertiesManifest`. A future milestone that wires phase 100 for real would resolve profiles
and configuration from a genuine source (e.g. environment/`.env`, config files) as an explicit pass,
rather than trusting whatever the caller happened to construct.

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
logic. `ContextScanner` is the sole DISCOVERER — the only class in this package allowed to reflect —
and captures method names onto the compiled `ContextManifest` at scan time; `InitDestroyInvoker` never
reflects a class at invocation time, only DISPATCHES against that already-compiled manifest.

`ApplicationContext::close()` — which runs every tracked `#[PreDestroy]` and `Lifecycle::stop()` (see
below) — is called automatically, at real application shutdown, without any application code having
to call it: `FireflyServiceProvider` wires it against Laravel's `$app->terminating()` hook in every
process that is **not** actually running inside an Octane worker (PHP-FPM, `queue:work`,
`schedule:run`, artisan commands, tests — `laravel/octane` may or may not even be installed there),
and against Octane's `WorkerStopping` event only when this process **is** an Octane worker right now
(see "The boot pipeline", above, and "Octane", below, for why the hook must differ by runtime, and for
why that split is decided by a runtime marker rather than merely whether the package is installed).

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

**Known gap, Octane only (a `#[Lazy]` singleton resolved for the first time inside a request):**
the promise above assumes the singleton was resolved into the *worker* application. That is true
for every eagerly-resolved singleton — `EagerSingletonsPass` (phase 900) builds it into the worker
once, at boot, before any request is served, and Octane's per-request `clone $this->app` then shares
that same object by reference into every sandbox — and true for every singleton under PHP-FPM,
where no worker/sandbox split exists at all. It is **not** true for a `#[Lazy]` singleton whose
*first* resolution happens inside a request: Octane builds it into the per-request **sandbox**
(`Laravel\Octane\Worker::handle()`'s `clone $this->app`), so the only strong reference to it lives in
that sandbox's own container state, never the worker's. `DisposableBeanRegistry` still records it —
the same `WeakReference`, via the same seam every bean passes through — but once the sandbox is
discarded at the end of that request, nothing keeps the bean alive, and it is silently
garbage-collected long before the worker's one-time `drainSingletons()` ever runs. **Its
`#[PreDestroy]` does not run.** Under PHP-FPM the identical bean's `#[PreDestroy]` fires correctly,
because there the resolving container and the terminating one are the same object — the gap is
Octane-specific, and it applies precisely to `#[Lazy]` + `#[PreDestroy]` on a Singleton, the
canonical "don't connect at boot, close at shutdown" shape. There is no supported workaround today
beyond avoiding that combination under Octane; see `DisposableBeanRegistry`'s own docblock for why
this is disclosed rather than fixed in this milestone.

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

`FireflyServiceProvider::register()` binds `ApplicationEventPublisher` to `DispatcherEventPublisher`
as a singleton automatically — a `#[Component]` that constructor-injects the interface above resolves
it with no further wiring required from application code. `BootLogger` below shows the other common
shape: a plain `#[Component]` with no constructor at all, whose methods are wired as listeners purely
via `#[AsEventListener]` — publishing and listening are independent, and a class is free to do only
one of them.

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

Singleton teardown must be symmetric with that: it must also happen exactly once per worker, not once
per request — getting this backwards was M4's own capstone bug. `ApplicationContext::close()` cannot
be wired to Laravel's `$app->terminating()` hook under Octane: that hook is `Application::terminate()`,
and Octane's `ApplicationGateway::terminate()` calls it through the **per-request sandbox** (a
`clone $this->app` made fresh by `Laravel\Octane\Worker` for every request/task/tick), so it fires at
the end of *every request*. Wiring `close()` there would drain every singleton's `#[PreDestroy]` and
stop every `Lifecycle` component after the worker's very first request, then keep serving requests
2..N against disconnected/stopped singletons — silently, since `close()` is idempotent and never
errors or repeats. So `FireflyServiceProvider` instead wires `close()` to Octane's `WorkerStopping`
event, which `Laravel\Octane\Worker` dispatches exactly once, at real worker shutdown — but **only**
when this process is actually running inside an Octane worker right now, not merely when
`laravel/octane` happens to be installed (see `FireflyServiceProvider::octaneIsAvailable()`'s own
docblock: package presence alone was a real regression — M4 review #4 — because `queue:work`,
`schedule:run`, artisan commands, and this package's own tests all have the package on their
classpath without ever starting a worker or dispatching `WorkerStopping`; every one of those still
gets `terminating()`, same as plain PHP-FPM). `WorkerStopping` carries only `$app` — there is no
per-worker-shutdown sandbox — but the listener closure doesn't read `$event` at all: it takes no
`$event` parameter and simply closes over the `ApplicationContext` built from this worker's own
`boot()`, captured once at worker-boot time. There is nothing on `WorkerStopping` worth reading in
the first place, which is *why* it carrying no `$sandbox` (unlike `RequestReceived`/
`RequestTerminated`/`TaskTerminated`/`TickTerminated`, see the invariant above) is a non-issue; see
`FireflyServiceProvider::bootApplicationContext()`'s own docblock.

By contrast, what *does* need resetting between requests is per-request state: `Scope::Scoped` bean
instances, and any `#[PreDestroy]` callbacks owed to them — never singletons. `FireflyServiceProvider`
wires an `OctaneListener` (only when this process is actually running inside an Octane worker — the
same `octaneIsAvailable()` gate as `close()`'s wiring above; harmless either way, since the events
below are only ever dispatched by a real worker) to Octane's `RequestReceived`,
`RequestTerminated`, `TaskTerminated`, and `TickTerminated` events. Each delegates to
`StateResetter::reset($event->sandbox)` — the **sandbox**, Octane's fresh-per-request container
clone, never `$event->app`, the original long-lived worker application; resetting the wrong one would
be a silent no-op on a container nothing is ever served from. In short: **scoped beans drain per
request; singletons drain once, at worker (or, under PHP-FPM, application) shutdown** — **for every
singleton resolved into the worker itself**, which is every eagerly-resolved one, and every one
under PHP-FPM. A `#[Lazy]` singleton first resolved *inside* a request is sandbox-lifetime instead,
and its `#[PreDestroy]` never runs — see "Lifecycle", above, for the full mechanism and why this is
disclosed rather than fixed in this milestone.

`StateResetter` first drains `DisposableBeanRegistry`'s scoped ledger — running `#[PreDestroy]` on
every tracked scoped bean, in reverse registration order — and only then calls
`Container::forgetScopedInstances()`, which has no destruction callback of its own and would otherwise
silently drop anything not drained first (a scoped bean holding a database transaction or file handle
would leak it). Resetting on both `RequestReceived` and `RequestTerminated` makes the reset
crash-resilient: a request that dies mid-flight cannot poison the next one, because the next request's
own `RequestReceived` resets before it runs.

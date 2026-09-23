<span class="eyebrow">Part IV — Observability, Testing & Delivery · Chapter 12</span>

# Testing LaraFly Applications {.chtitle}

By the end of this chapter you will know `firefly/testing`'s two boot-harness families — `FireflyTestCase`/`FireflyDatabaseTestCase` for Testbench-style class tests, and `bootFireflyApp()`/`fireflyApplication()` for bare boots with no test framework glue at all — the eleven recording doubles that let a test assert on what a Firefly port actually saw (something Laravel's own `Event::fake()`/`Bus::fake()` cannot do, because they have never heard of Firefly's ports), the five Firefly-flavored Pest expectations, the `WebSliceTestCase`/`DataSliceTestCase` pair that boots only the beans a test needs, the Pest 4 browser suite that drives the **shipped skeleton** in the same process — same container, same SQLite database, no second server — and why `assertNoJavaScriptErrors()` is the assertion that earns it, the helpers that sign a test in (`actingAsAuthentication()` and the `actingAsPrincipal()`/`actingAsOidcUser()` pair built on it, the declarative `#[WithMockUser]`, and `withoutSecurity()` for the other direction), and — because this book only teaches what is actually shipped — a real, honest limitation `samples/lumen`'s own tests ran into and how they worked around it.

!!! note "New term: slice test"
    A **slice test** boots only the narrow vertical of the framework a test actually exercises — the web pipeline over one controller, or the data pipeline over one repository — rather than the whole application. It is Spring Boot's `@WebMvcTest`/`@DataJpaTest` idea: faster boots, and a mis-wired slice fails immediately instead of quietly working by accident because some unrelated bean happened to be present too.

---

## The boot harness: `FireflyTestCase`

Every hand-rolled Firefly test base across the monorepo used to re-derive the same handful of boot-order rules by hand. `FireflyTestCase` absorbs them once, as a `Testbench\TestCase` subclass with exactly three hooks to override:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
/**
 * The Firefly capability providers this test needs, in registration order.
 * FireflyAutoConfigureServiceProvider is ALWAYS prepended by the harness — do NOT list it here.
 *
 * @return list<class-string<ServiceProvider>>
 */
protected function fireflyProviders(): array
{
    $attribute = $this->fireflyTestAttribute();

    return $attribute instanceof FireflyTest ? $attribute->providers : [];
}
// …
protected function configOverrides(): array
{
    $attribute = $this->fireflyTestAttribute();

    return $attribute instanceof FireflyTest ? $attribute->config : [];
}
// …
/**
 * Lazily-resolved bindings / manifest instances, bound AFTER config. Override to
 * $app->instance(SomeManifest::class, ...) or bind a port fake for the whole test class.
 */
protected function defineFireflyEnvironment(Application $app): void
{
    // no-op by default
}
```

`configOverrides()` returns eager, dot-keyed configuration seeded **before** boot, so that a `#[ConditionalOnProperty]` or `#[ConditionalOnMissingBean]` pass — which scans at *register* time — actually observes it. That is the seam for `firefly.scan.paths` and for any `firefly.<feature>.*` flag a test needs on. Each of the three hooks also has a class-level form: `#[FireflyTest(providers: […], config: […])]` on the test class does the same job without an override.

The base class handles the parts every test used to get subtly wrong: `FireflyAutoConfigureServiceProvider` is *always* registered first (every capability provider's own `register()` assumes the kernel already exists), `configOverrides()` is applied before boot so a `#[ConditionalOnProperty]` scanning at register time actually observes it, and the log channel is switched to `errorlog` so a read-only sandbox never trips over a filesystem-backed logger. A minimal subclass needs nothing more than the one hook it actually uses:

<!-- illustrative: a test the reader writes in their own application, which by definition is not a file in this repository -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\FireflyTestCase;

final class WidgetProbeTest extends FireflyTestCase
{
    protected function configOverrides(): array
    {
        return ['firefly.scan.paths' => [
            'App\\Widgets\\' => app_path('Widgets'),
        ]];
    }
}
```

Two small guardrails round the base out: `app(): Application` throws a clear `LogicException` if you call it before boot, rather than handing back an untyped `null`, and `fireflyContext(): ApplicationContext` gives you straight back the same boot-engine facade Chapter 2 introduced, already resolved.

---

## Database tests: `FireflyDatabaseTestCase`

`FireflyDatabaseTestCase` extends `FireflyTestCase` and mixes in `UsesSqliteMemory`, which binds a shared sqlite `:memory:` connection as the `testing` default — no Docker, no fixture database file, no cross-test state:

<!-- illustrative: the reader's own database test, with the schema their application owns -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;

final class WidgetRepositoryTest extends FireflyDatabaseTestCase
{
    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->createSchema('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
}
```

`createSchema(string $table, Closure(Blueprint): void $blueprint): void` is a thin wrapper over `Schema::create()` — nothing new to learn if you already know Laravel migrations, just a shorter path from "I need a table for this one test" to a running schema.

---

## Bare boots: `bootFireflyApp()` and `fireflyApplication()`

Not every test wants a full Testbench `TestCase`. `Firefly\Testing\functions.php` — Composer `files`-autoloaded, so these are always available with no `use` statement — ships a second family for a bare boot:

<!-- source: packages/testing/src/functions.php -->
```php
function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application
// …
function bootFireflyApp(array $config = [], array $providers = [], array $bindings = [], array $needs = []): ApplicationContext
```

`$config` is the full config array; `$providers` register **after** `FireflyAutoConfigureServiceProvider`, which is always registered first regardless; `$bindings` are `$app->instance()` overrides bound *before* provider registration — the seam for handing in a port double; and `$needs` is a small "missing-bindings menu" (`'cache'`, `'validation'`, `'http'`) that binds a fresh, isolated fallback for a capability a bare boot would otherwise lack entirely:

<!-- illustrative: the reader's own bare-boot test over their application's handlers -->
```php
<?php

declare(strict_types=1);

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;

it('boots a bare cqrs app with AutoConfigure first and an explicit binding', function () {
    $context = bootFireflyApp(
        config: ['firefly' => ['cqrs' => []]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class],
        bindings: [HandlerManifest::class => new HandlerManifest([], [])],
    );

    expect($context->has(HandlerManifest::class))->toBeTrue();
});
```

`bootFireflyApp()` is the identical call, returning the booted `ApplicationContext` instead of the raw `Application` — useful whenever a test wants to reach straight for a bean via the context facade rather than Laravel's own container API.

---

## Recording doubles: testing without `Event::fake()`

Laravel's `Event::fake()`/`Bus::fake()` intercept *Laravel's own* event and job dispatch — they have no visibility into `firefly/eda`'s `EventPublisher`, `firefly/cqrs`'s `CommandBus`, or any other Firefly port. `firefly/testing` ships a recording or stub double for exactly that: a plain implementation of the real port that records what it saw, so a test can assert on behavior instead of re-implementing the framework's own internals.

| Port | Double (`Firefly\Testing\Double\*`) | Records |
|---|---|---|
| `Firefly\Eda\EventPublisher` | `RecordingEventPublisher` | `$published` — `{destination, eventType, payload, headers}`; `publishedOf(string $eventType)` filters |
| `Firefly\Context\Event\ApplicationEventPublisher` | `RecordingApplicationEventPublisher` | `$events` — every published event object, in order; `ofType(string $class)` filters |
| `Firefly\Cqrs\Command\CommandBus` | `RecordingCommandBus` | `$sent`; `willReturn(string $commandClass, mixed $result)` programs a canned result; `handled(string $class)` filters |
| `Firefly\Cqrs\Query\QueryBus` | `StubQueryBus` | `$asked`; `willReturn(string $queryClass, mixed $result)` programs a canned result |
| `Firefly\Cqrs\Metrics\CqrsMetrics` | `RecordingCqrsMetrics` | `$commandSuccesses`/`$commandFailures`/`$querySuccesses`/`$queryFailures` |
| `Firefly\Cqrs\Event\CommandEventPublisher` | `RecordingCommandEventPublisher` | `$published` (list of `DomainEvent`); an optional constructor `?Throwable $throw` exercises the bridge's failure path |
| `Firefly\Messaging\MessageBrokerPort` | `RecordingMessageBroker` | `$published` — `{topic, value, key, headers}`; `publishedTo(string $topic)` filters |
| `Firefly\Scheduling\Lock\DistributedLock` | `RecordingDistributedLock` | `$acquired`/`$released`; constructor `(bool $available = true)`; `setAvailable()` toggles lock grant |
| `Firefly\Actuator\Health\HealthIndicator` | `FakeHealthIndicator` | Defaults `Status::Up`; constructor takes a `Health`, `setHealth()` reprograms it |
| `Firefly\Observability\Tracing\Tracer` | `RecordingTracer` | `$spans` — every traced span name, in call order; still faithfully invokes and returns the traced callback |
| `Firefly\Context\Event\ApplicationEventPublisher` | `RecordingAuthenticationEvents` | `$events`, plus the security family by name: `successes()`, `interactive()`, `failures()`, `logouts()`, `denials()`; an optional constructor `?ApplicationEventPublisher $forwardTo` re-publishes every recorded event for real |

Two rows name the same port, and the difference between them is what a security test needs. `RecordingApplicationEventPublisher` records objects; `RecordingAuthenticationEvents` also knows the five security event kinds by name, so a sign-in flow test reads `$this->events->failures()[0]->username` instead of filtering a mixed list by class. It is the double every listing in Chapter 10's sign-in, logout and denial sections calls `$this->events`.

Its docblock carries the two rules that make it work, and both are easy to get wrong:

<!-- source: packages/testing/src/Double/RecordingAuthenticationEvents.php -->
```php
/**
 // …
 * Bind it BEFORE boot — `$app->instance(ApplicationEventPublisher::class, $events)` from
 * defineFireflyEnvironment() — so firefly/security's AuthenticationEventPublisher bean wraps it; the
 * framework's own default is a bound()-guarded provider binding, which is why an instance() wins there.
 // …
 */
final class RecordingAuthenticationEvents implements ApplicationEventPublisher
{
    /** @var list<object> */
    public array $events = [];

    public function __construct(private readonly ?ApplicationEventPublisher $forwardTo = null) {}
```

The first rule is the binding moment: an `instance()` registered from `defineFireflyEnvironment()` is already there when the security provider's `bound()`-guarded default would have run, so the spy wins — bind it after boot and the framework's own publisher is what the filters hold.

The second is `$forwardTo`, and it exists because binding the spy **replaces** the port. Nothing registered against the dispatcher hears a security event any more: an `#[AsEventListener]` on `InteractiveAuthenticationSuccessEvent` stays silent for the whole suite, which is how a suite whose pipeline includes such a listener (`firefly/security-oauth2-server` stamps the sign-in instant that way) breaks without an error. Hand the framework's own publisher in — `new RecordingAuthenticationEvents(new DispatcherEventPublisher($app))` — and every event is recorded *and* published for real. The default, `null`, keeps the spy a pure recorder, which is right for a suite that only reads.

`RecordingEventPublisher`, in full, shows the shape every double in the table follows — a real port implementation, plus a plain array that just remembers what happened:

<!-- source: packages/testing/src/Double/RecordingEventPublisher.php -->
```php
final class RecordingEventPublisher implements EventPublisher
{
    /** @var list<array{destination: string, eventType: string, payload: array<string,mixed>, headers: array<string,string>}> */
    public array $published = [];

    public function subscribe(string $eventTypePattern, callable $handler): void {}

    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->published[] = compact('destination', 'eventType', 'payload', 'headers');
    }

    public function start(): void {}

    public function stop(): void {}
    // …
    public function publishedOf(string $eventType): array
    {
        return array_values(array_filter($this->published, static fn (array $e): bool => $e['eventType'] === $eventType));
    }
}
```

<!-- illustrative: the four calls a reader makes against a recording double from their own test -->
```php
<?php

use Firefly\Testing\Double\RecordingEventPublisher;

$publisher = new RecordingEventPublisher;
$publisher->publish('accounts.events', 'AccountOpened', ['owner' => 'alice'], ['x' => '1']);

expect($publisher->published)->toHaveCount(1)
    ->and($publisher->published[0]['eventType'])->toBe('AccountOpened');
```

---

## Firefly-flavored Pest expectations

`Firefly\Testing\Pest\FireflyExpectations::register()` installs a small set of custom `expect()` matchers, called once from the monorepo's own root `tests/Pest.php`:

<!-- source: tests/Pest.php -->
```php
use Firefly\Testing\Pest\FireflyExpectations;
// …
FireflyExpectations::register();
```

There are exactly **five**:

- **`toHavePublished(string $eventType, array $payloadContains = [])`** — on a `RecordingEventPublisher`; at least one published event of the given type whose payload contains the given key/value subset.
- **`toHaveHandledCommand(string $class)`** — on a `RecordingCommandBus`; at least one sent command is an instance of `$class`.
- **`toBeUp()`** — on a `Health` or a `HealthIndicator` (narrowed via `->health()` automatically); asserts `Status::Up`.
- **`toHaveRecordedMetric(string $name, array $tags = [])`** — on anything exposing `meters(): list<Meter>` (e.g. a `MeterRegistry`); at least one recorded meter with the given name and tag subset.
- **`toBeProblemDetails(int $status)`** — on a `TestResponse` (checks the `application/problem+json` content type and decodes the body) or a plain array; asserts the RFC-7807 shape has `type`/`title`/`status` keys and that `status` matches.

<!-- illustrative: the expectations a reader writes in their own test -->
```php
<?php

use Firefly\Testing\Double\RecordingCommandBus;

// @phpstan-ignore method.notFound
expect($commandBus)->toHaveHandledCommand(PlaceOrder::class);
```

!!! note "The `@phpstan-ignore method.notFound` comments, explained"
    Pest installs these via `expect()->extend(...)` **at runtime** — PHPStan's static reflection of the vendor `Pest\Expectation` class has no way to see a method added this way, so every call is flagged `method.notFound` even though it genuinely exists once `tests/Pest.php` has run. Keep each custom expectation on its own `expect()` statement rather than chaining with `->and(...)`: chaining degrades the precisely-ignorable `method.notFound` into the unignorable `method.nonObject`.

For a plainer, PHPUnit-flavored assertion instead of the fluent expectations, two procedural helpers live alongside `bootFireflyApp()` in the same autoloaded `functions.php`:

<!-- source: packages/testing/src/functions.php -->
```php
function assertEventPublished(object $publisher, string $eventType, array $payloadContains = []): void
// …
function assertNoEventsPublished(object $publisher): void
{
    expect($publisher->published)->toBeEmpty('Expected no events to have been published.');
}
```

!!! warning "A real, honest limitation: `toBeProblemDetails()` and the `type` field"
    `samples/lumen`'s own `Web\WalletRestTest.php` documents, in a code comment right next to the tests it affects, a genuine gap: `toBeProblemDetails()` asserts the response body has a `type` key, but the framework's real RFC-7807 renderer (`Firefly\Kernel\Error\ErrorResponse::fromException()`, rendered by `Firefly\Web\Exception\ProblemDetailsRenderer`) never populates `type` — it is an optional field only emitted when explicitly passed, which the exception-to-response path never does. Calling `toBeProblemDetails()` against a genuine `404`/`422`/`403`/`409` this framework actually produces fails, every time, for exactly that reason.

    This is why Lumen's own tests — like `firefly/web`'s own capstone test suite — assert the RFC-7807 fields directly instead:

<!-- source: samples/lumen/tests/Web/WalletRestTest.php -->
```php
it('returns RFC-7807 problem+json for an unknown wallet', function () {
    // …
    $this->getJson('/api/v1/wallets/nope/balance')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('title', 'Not Found');
```

A book that only teaches what genuinely works would be doing you a disservice by hiding this: `toBeProblemDetails()` is real, shipped, and exercised by the testing package's own suite against a body that *does* carry a `type` key — it simply is not the assertion Lumen reaches for against the framework's actual error responses, and now you know why, instead of hitting the same failure cold.

---

## Slice testing: `WebSliceTestCase` and `DataSliceTestCase`

A slice test boots only the beans a PSR-4 scan discovers for one narrow vertical, plus whatever explicit port overrides you supply — Spring's `@WebMvcTest`/`@DataJpaTest` idea, ported. Both bases expose a method, callable straight from a Pest closure test:

<!-- source: packages/testing/src/Slice/WebSliceTestCase.php -->
```php
abstract class WebSliceTestCase extends FireflyTestCase
{
    // …
    public function webSlice(array $scan = [], array $overrides = []): ApplicationContext
    {
        if ($this->sliceRequested && ($scan !== $this->sliceScan || $overrides !== $this->sliceOverrides)) {
            throw new LogicException('webSlice() is per-test-class; call it once with a single slice definition.');
        }
```

`DataSliceTestCase::dataSlice()` is the same method for the persistence vertical. Both are **per test class**: calling one twice with different slice definitions is a `LogicException` rather than a silent second boot, because the second definition would apply to every test in the class and not only to the ones written after it.

<!-- illustrative: the reader's own web-slice test over their own controller -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Slice\WebSliceTestCase;

uses(WebSliceTestCase::class);

it('boots a web slice and serves a sliced controller route', function () {
    $this->webSlice(scan: [
        'App\\Http\\Slice\\' => base_path('tests/Fixtures/Slice'),
    ]);

    $response = $this->get('/slice/ping');

    expect($response->status())->toBe(200);
});
```

`WebSliceTestCase` boots the real web + validation pipeline — including route wiring — over only the sliced controllers, so `$this->get(...)` serves a genuine `TestResponse` against them. `DataSliceTestCase` boots the real data pipeline over only the sliced beans, then eagerly resolves every one of them, so a mis-wired slice fails immediately at boot instead of passing by accident because nothing in the test happened to touch the broken bean:

<!-- illustrative: the reader's own data-slice test over their own repository -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Slice\DataSliceTestCase;

uses(DataSliceTestCase::class);

it('boots ONLY the sliced data beans and resolves them', function () {
    $context = $this->dataSlice(
        scan: ['App\\Domain\\Widgets\\' => base_path('tests/Fixtures/Widgets')],
        overrides: [PricingPort::class => new FakePricingPort],
    );

    expect($context->get(WidgetRepository::class))->toBeInstanceOf(WidgetRepository::class);
});
```

Both methods are **per-test-class** — calling either a second time with different arguments throws a `LogicException`, so a slice's shape is fixed for the whole test class rather than mutable mid-test.

### `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]` attributes

For a hand-written, PHPUnit-style test **class** rather than a Pest closure file, three class-target attributes give the same three shapes declaratively, read by `setUp()` before Testbench's own boot ever runs:

<!-- illustrative: the reader's own attribute-style slice test -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Attributes\WebSlice;
use Firefly\Testing\Slice\WebSliceTestCase;

#[WebSlice(scan: ['App\\Http\\Slice\\' => __DIR__.'/Fixtures/Slice'])]
final class SliceControllerTest extends WebSliceTestCase
{
    public function test_web_slice_attribute_boots_the_sliced_route(): void
    {
        $response = $this->get('/slice/ping');

        $this->assertSame(200, $response->status());
    }
}
```

!!! warning "Attributes vs. method calls — do not mix the two on one test class"
    `#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` only work on a hand-written class extending the matching base — not on a Pest closure file. A Pest closure test calls `$this->webSlice(...)`/`$this->dataSlice(...)` directly instead, because the method internally calls `refreshApplication()` to re-boot with the slice values — Testbench has already performed an empty first boot in `setUp()` before the closure body ever runs. Pick one style per test class.

---

## The fixture layer

Three small helpers round out the kit, each replacing a hand-rolled equivalent that used to be duplicated per package.

`FixtureRegistry` is a thin named-fixture registry over plain factory closures — the Firefly convenience sitting alongside Eloquent factories, not a replacement for them:

<!-- source: packages/testing/src/Fixture/FixtureRegistry.php -->
```php
final class FixtureRegistry
{
    // …
    public function register(string $name, Closure $factory): self
    // …
    public function has(string $name): bool
    // …
    public function make(string $name, array $overrides = []): object
    // …
    public function load(string ...$names): array
```

<!-- illustrative: the fixtures a reader registers for their own aggregate -->
```php
<?php

use Firefly\Testing\Fixture\FixtureRegistry;

$registry = (new FixtureRegistry)->register('widget', function (array $overrides): Widget {
    return new Widget(
        $overrides['name'] ?? 'default',
        $overrides['qty'] ?? 1,
    );
});

$bolt = $registry->make('widget', ['name' => 'bolt', 'qty' => 5]);
$two = $registry->load('widget', 'widget'); // list<Widget>, 2 entries
```

`AggregateSeeder` replays an aggregate's domain events through an `ApplicationEventPublisher` port — useful for seeding a projection (Chapter 6's ledger, for instance) without re-running the whole command flow that originally produced those events:

<!-- illustrative: the seeding a reader does from their own test -->
```php
<?php

use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Firefly\Testing\Fixture\AggregateSeeder;

$publisher = new RecordingApplicationEventPublisher;
(new AggregateSeeder)->publishEvents($publisher, new WidgetCreated('a'), new WidgetCreated('b'));

expect($publisher->events)->toHaveCount(2);
```

`ListenerSpy` records arbitrary string values a listener or handler fixture saw, in call order — the one shared replacement for what used to be a hand-rolled `Spy` class in every package that needed one:

<!-- illustrative: the listener spy a reader drives from their own test -->
```php
<?php

use Firefly\Testing\Fixture\ListenerSpy;

$spy = new ListenerSpy;
$spy->record('order.placed');
$spy->record('order.shipped');

expect($spy->seen)->toBe(['order.placed', 'order.shipped']);
```

---

## Real backends: `RequiresDocker` and `fireflyConfigFor()`

Some tests need a genuine backend — a real Postgres, a real Kafka broker — rather than an in-memory double. These are tagged `@group integration` and excluded from the default gate entirely: `vendor/bin/pest` (the whole-branch gate `composer test` runs) never touches one unless you explicitly ask with `vendor/bin/pest --group=integration`.

`RequiresDocker` is a trait (`@mixin PHPUnit\Framework\TestCase`) that turns "Docker is unavailable" into a clean skip rather than a hard failure, so a Docker-less machine that *does* run the integration group still gets a green result:

<!-- source: packages/testing/src/Integration/RequiresDocker.php -->
```php
trait RequiresDocker
{
    protected function skipUnlessDocker(): void
    {
        if (! is_docker_available()) {
            $this->markTestSkipped('Docker is not available; skipping @group integration test.');
        }
    }
}
```

`is_docker_available()` runs `docker info` and returns `true` iff it exits `0` — it never throws, so calling it with no Docker installed at all is always safe. A real Kafka-backed integration test base shows the pattern end to end:

<!-- illustrative: the reader's own container-backed integration test -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Integration\RequiresDocker;
use PHPUnit\Framework\TestCase;

abstract class KafkaIntegrationTestCase extends TestCase
{
    use RequiresDocker;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();
        parent::setUp();
    }
}
```

`fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array` — the `@ServiceConnection` analogue — maps a started testcontainer into a flat, dot-keyed config array, duck-typed against whichever of `getHost()`/`getMappedPort()`/`getUsername()`/`getPassword()`/`getDatabase()` the container object actually exposes, so it works against any testcontainers-php container class with no hard dependency on the library's types:

<!-- source: packages/testing/src/functions.php -->
```php
/**
 * Map a started testcontainer into a flat Firefly/Laravel config array (the @ServiceConnection analog).
 *
 * @return array<string,mixed>
 */
function fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array
```

Feed the result straight into a `FireflyTestCase` subclass's `configOverrides()`, or set each key on the config repository from `defineFireflyEnvironment()`.

---

## Proof from Lumen: how the sample dogfoods it all

`samples/lumen` builds its own test base, `LumenTestCase`, on top of `FireflyDatabaseTestCase` — the pattern every chapter of this book has ultimately been resting on:

<!-- source: samples/lumen/tests/LumenTestCase.php -->
```php
abstract class LumenTestCase extends FireflyDatabaseTestCase
{
    // …
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
        ];
    }
    // …
    protected function configOverrides(): array
    {
        return [
            // …
            'firefly.scan.paths' => $this->scanPaths(),
            // …
            'firefly.security.enabled' => true,
            // …
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
        // …
        ];
    }
// …
}
```

Every one of Chapter 4 through Chapter 11's HTTP calls in this book — every `$this->postJson(...)`, every RFC-7807 assertion — runs against a `LumenTestCase` subclass. It deliberately binds the *real* `InMemoryEventBus` as the `EventPublisher` (never a `RecordingEventPublisher`), because Lumen's own tests need the domain-to-integration bridge from Chapter 8 to genuinely fire, not merely to be recorded as if it had.

!!! laravel "Laravel parity"
    Laravel's own test kit (`RefreshDatabase`, `Event::fake()`, `Bus::fake()`) is excellent for Laravel's own primitives, but it has no visibility into a Firefly port — a `CommandBus::send()` or an `EventPublisher::publish()` call is invisible to `Event::fake()`, because nothing about it goes through Laravel's own event dispatcher. `firefly/testing`'s recording doubles and Pest expectations are the parity layer: the same "arrange a fake, assert on what it saw" idiom you already know from Laravel, aimed at the ports this book's other chapters actually introduced.

---

## A real browser over the real app

Everything so far in this chapter asserts on objects and on HTTP responses. Neither can tell you whether the welcome page actually rendered, whether the admin dashboard's charts drew, or whether Swagger UI threw a JavaScript error while building its operation list. For that you need a browser — and the reason most projects do not have browser tests is that the usual setup is miserable: a second process serving the app, a second database, a second container, and a suite that drifts from the one the unit tests exercise.

LaraFly's browser suite has none of that, because of one property worth stating plainly: **the browser drives the same booted application the rest of the suite does.**

Pest 4 plus `pestphp/pest-plugin-browser` serves the Testbench-booted application to Chromium **in process**. There is no `php -S`, no separate document root, no second connection — the same container, and the same in-memory SQLite database the test seeded:

<!-- source: tests/Browser/Support/BrowserTestCase.php -->
```php
/**
 * The application every browser scenario drives: the shipped skeleton, compiled by the real firefly:cache
 * writer and booted under Testbench with the provider set a created app gets — served to Chromium by the
 * plugin's in-process server, so the test keeps the container and the SQLite connection.
 // …
 */
abstract class BrowserTestCase extends SkeletonExampleTestCase
{
    use SeedsOrders;

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return DiscoveredProviders::forSkeleton();
    }
```

And it is the **skeleton** that gets driven, compiled by the real `firefly:cache` writer — the same project `composer create-project` produces. A browser test failing is therefore evidence about what a new user's application does, not about a fixture built to pass.

A scenario reads the way you would describe it out loud:

<!-- source: tests/Browser/WelcomeAndApiTest.php -->
```php
it('renders the welcome page with the live route table', function (): void {
    /** @var BrowserTestCase $this */
    visit('/')
        ->assertSee('Hello, LaraFly')
        ->assertSee('Your routes')
        ->assertSee('/orders/{id}')
        ->assertSee('OrderController')
        ->assertSee('GreetingController')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'welcome');
});
// …
it('serves the OpenAPI viewer, and Swagger UI draws the operations without a JavaScript error', function (): void {
    /** @var BrowserTestCase $this */
    visit('/openapi')
        ->assertPresent('#swagger-ui')
        ->wait(2)
        ->assertSee('/orders/{id}')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-viewer');
});
```

`assertNoJavaScriptErrors()` is on almost every scenario, and it is the assertion that pays for the suite. A page that renders and quietly throws in the console is a page that looks fine in a screenshot and is broken for the person using it.

### Why it does not slow your gate down

Browser tests need Node and a Chromium download. The default gate must not:

<!-- source: phpunit.xml.dist -->
```xml
<testsuite name="unit">
    <directory>tests</directory>
    <directory>packages/*/tests</directory>
    <directory>samples/lumen/tests</directory>
    <exclude>tests/Browser</exclude>
</testsuite>
<testsuite name="browser">
    <directory>tests/Browser</directory>
</testsuite>
```

Two suites, and `tests/Browser` is excluded from the default one. So `composer check` — Pint, PHPStan, Pest and Deptrac — stays entirely Node-free, and `composer test:browser` runs the browser suite alone. CI has a separate `Browser (Pest + Playwright)` job that runs it and uploads every screenshot as an artifact, so a failure comes with a picture of what the page looked like.

The **suite split** is what does the work here, not the group label:

<!-- source: tests/Pest.php -->
```php
// Browser tests: everything under tests/Browser drives a real Chromium through
// pestphp/pest-plugin-browser against the Testbench-booted app (the plugin serves
// it in-process). tests/Browser is its own `browser` testsuite in phpunit.xml.dist
// and is excluded from the default `unit` suite, so the default gate never needs
// Node; `composer test:browser` runs this suite alone. The `browser` group is a
// label only (for --group=browser filtering): the suite split in phpunit.xml.dist
// is what keeps these files out of the default run — Pest includes every file of
// the configured suite before it filters groups, and the plugin boots Playwright
// the moment a file under tests/Browser is included.
pest()->group('browser')->in('Browser');

pest()->browser()
    ->inChrome()
    ->inLightMode()
    ->timeout(10000);
```

That comment is worth reading twice, because the distinction it draws catches people out: Pest **includes** every file of the configured suite before it filters by group, and the plugin boots Playwright the moment a file under `tests/Browser` is included. A `--exclude-group=browser` would therefore still pay the Playwright cost. Only the suite split avoids it.

!!! warning "The plugin forces `app.debug=false`"
    Two framework keys default to `app.debug` — `firefly.web.error-page.trace` and `firefly.admin.enabled` — and the plugin forces `app.debug=false` while it handles each browser request. A suite that relied on those defaults would render the *production* error page while meaning to test the debug one. So `BrowserTestCase` switches every surface it means to look at **on by its own key**, and subclasses override `trace()` and `securityOverrides()` — both read before boot, which is why they are hooks rather than per-test calls.

And a browser authenticates the way a person does: `SecuredBrowserTestCase` puts the framework's own form login and two in-memory users in front of the application, so the login scenarios drive the same `FormLoginFilter` Chapter 10 described rather than a testing shortcut.

## Signing a test in

Which brings us to the helpers the security chapters lean on. Three methods, a fourth attribute beside the three in `Firefly\Testing\Attributes\`, and one switch that turns the whole stack off. The three methods are built one on the next:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
public function actingAsAuthentication(Authentication $authentication): static
{
    SecurityContextHolder::setContext(new SecurityContext($authentication));
    $this->app()->instance(ActingPrincipal::class, new ActingPrincipal($authentication));

    $kernel = $this->resolveHttpKernel();
    if ($kernel instanceof FoundationHttpKernel && ! $kernel->hasMiddleware(ActingPrincipalMiddleware::class)) {
        $kernel->prependMiddleware(ActingPrincipalMiddleware::class);
    }

    return $this;
}
```

`actingAsAuthentication()` is the seam: it sets the holder immediately, binds the principal, and prepends a middleware **once**, so a second call swaps who the next request is rather than stacking another copy on the kernel. The middleware is the outermost link on purpose — `SecurityContextPersistenceFilter` leaves an already-authenticated holder alone, and the framework filters that clear the holder in their own `finally` run inside it, so the principal is back on the holder when the request returns.

`actingAsPrincipal('ada', ['ROLE_USER'])` is the everyday form, built on it. And for the OAuth2 chapters there is Spring Security's `oidcLogin()`, as a method:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
    /**
     * Run the rest of this test as a person who signed in through OpenID Connect (Spring Security's
     * `oidcLogin()` test support, as a method): the principal is a real DefaultOidcUser over an id token whose
     * claims are `{iss: https://idp.test, sub: user, aud: firefly-app, iat, exp}` overlaid by $claims — and
     * NO raw token value, exactly the shape the session hands a controller after a real login — so
     * `#[AuthenticationPrincipal] OidcUser $user`, `getClaim()`, `getEmail()` and the rest all answer. Its
     * authorities are OIDC_USER (an OidcUserAuthority carrying the claims) and SCOPE_x per $scopes; the
     * Authentication carries those plus $authorities (where a GrantedAuthoritiesMapper would have put ROLE_*),
     * and $registrationId on its attributes, which the authorized-client manager and RP-initiated logout read.
     * The principal is named by $nameAttributeKey read from the claims (`sub` by default), so a claim for it
     * must exist. Nothing here talks to a provider: no registration, no discovery, no token.
     // …
     */
    public function actingAsOidcUser(array $claims = [], array $authorities = [], string $registrationId = 'oidc', array $scopes = ['openid'], string $nameAttributeKey = 'sub'): static
```

`actingAsOidcUser()` builds a **real** `DefaultOidcUser`, with the authorities a real login would have granted — `OIDC_USER` plus a `SCOPE_x` per scope — and the registration id on the token's attributes, which is what the authorized-client manager and RP-initiated logout read. Nothing here talks to a provider: no registration, no discovery, no token request. And it deliberately carries **no raw token value**, because that is exactly the shape the session hands a controller after a real sign-in; a test that gets a raw token would be testing something the application never sees.

`#[WithMockUser]` is Spring's `@WithMockUser`, and it is the declarative form of `actingAsPrincipal()` — the fourth attribute in `Firefly\Testing\Attributes\`, beside the three slice attributes above:

<!-- source: packages/testing/src/Attributes/WithMockUser.php -->
```php
/**
 * Spring's @WithMockUser: run the test (or every test of the class) as a signed-in principal. `roles` are
 * prefixed with `ROLE_` unless already so; `authorities` are taken verbatim. A method-level attribute wins
 * over a class-level one. Honoured by FireflyTestCase::setUp() through actingAsPrincipal(), so it covers
 * direct calls, HTTP requests through the filters, the dispatcher guard and proxied beans alike.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithMockUser
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $authorities
     */
    public function __construct(
        public string $name = 'user',
        public array $roles = ['USER'],
        public array $authorities = [],
    ) {}
```

Unlike the three slice attributes, this one is read in `setUp()` on *any* `FireflyTestCase`, method-level beating class-level, so a hand-written test class can sign its whole suite in once and name the exception on the one method that needs a different principal.

And for the other direction there is `withoutSecurity()`:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
    /**
     * Switch the security stack off for the rest of this test: the URL filter, the CSRF filter, every
     * authentication filter, the proxy's method-security link and the CQRS bus authorizers (through
     * MethodSecurityMessageEnforcer, which DefaultCommandBus/DefaultQueryBus hold by constructor) read their
     * flags live, and the dispatcher guard is rebound to the no-op default. Beans already built stay built;
     * only their gates change — so a controller test that dispatches a command whose handler carries a
     * #[PreAuthorize] gets the handler's answer, not a 401.
     */
    public function withoutSecurity(): static
```

Read the last sentence twice, because it is the reason this method exists at all rather than a config override: the flags are read **live**, on every request, so flipping them mid-test changes the gates on beans that were already built. A test that only wants to exercise the thing *behind* the gate does not have to re-boot the application to get past it.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `FireflyTestCase` | Testbench base; three hooks (`fireflyProviders`/`configOverrides`/`defineFireflyEnvironment`); config seeded before boot |
| `FireflyDatabaseTestCase` / `UsesSqliteMemory` | Adds a shared sqlite `:memory:` connection + `createSchema()` |
| `bootFireflyApp()` / `fireflyApplication()` | Bare, no-Testbench boots; a `$needs` menu fills in missing cache/validation/http fallbacks |
| 11 recording doubles | Real port implementations that record what they saw — the parity layer `Event::fake()` can't reach |
| 5 Pest expectations | `toHavePublished`, `toHaveHandledCommand`, `toBeUp`, `toHaveRecordedMetric`, `toBeProblemDetails` |
| `WebSliceTestCase` / `DataSliceTestCase` | Boot only a PSR-4-scanned slice + explicit overrides; per-test-class, fail-fast on data |
| `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]` | Class-attribute analog of the same three shapes, for hand-written test classes |
| `FixtureRegistry` / `AggregateSeeder` / `ListenerSpy` | Named fixtures; domain-event replay; a shared call-order spy |
| `RequiresDocker` / `fireflyConfigFor()` | `@group integration` opt-in; skip-not-fail without Docker; testcontainer → config mapping |
| The `browser` suite | Pest 4 + `pest-plugin-browser` serving the Testbench-booted **skeleton** in process — same container, same SQLite, no second server |
| `phpunit.xml.dist`'s two suites | `tests/Browser` excluded from `unit`, so `composer check` stays Node-free; `composer test:browser` runs it alone |
| `assertNoJavaScriptErrors()` | The assertion that earns the suite: a page that renders and throws in the console looks fine in a screenshot |
| `actingAsAuthentication()` | The seam under `actingAsPrincipal()` and `actingAsOidcUser()`; middleware prepended once, so a second call swaps the principal |
| `actingAsOidcUser()` | Spring's `oidcLogin()` as a method: a real `DefaultOidcUser`, real authorities, **no raw token** — the shape a session really hands a controller |
| `RecordingAuthenticationEvents` | The security-aware double: the security event family by name (`successes`/`interactive`/`failures`/`logouts`/`denials`); bind it before boot, and pass `$forwardTo` when real listeners must still hear |
| `#[WithMockUser]` | Spring's `@WithMockUser`: the declarative `actingAsPrincipal()`, read in `setUp()`, method-level beating class-level |
| `withoutSecurity()` | Every security flag off for the rest of the test — read live, so beans already built change gates without a re-boot |

---

## Try it yourself {.exercises}

1. **Write a slice test for one of Lumen's own controllers.** Using `WebSliceTestCase`, scan only `Lumen\Web\` and confirm you can serve one wallet endpoint without booting CQRS, EDA, or security at all — then note which requests fail, and why, once you understand exactly which beans the slice left out.
2. **Reproduce the `toBeProblemDetails()` gap yourself.** Call it against a real `404` response from Lumen's own REST API and confirm it fails with the missing-`type`-key message this chapter described — then rewrite the same assertion the way `WalletRestTest.php` actually does it, and confirm that one passes.
3. **Run the browser suite, then break a page.** Run `composer test:browser` and look at `tests/Browser/Screenshots`. Then add a `<script>` that throws to the welcome view and re-run: confirm the page still renders, still passes every `assertSee`, and that `assertNoJavaScriptErrors()` is the only assertion that catches it.
4. **Prove the suite split matters.** Time `composer test` and confirm nothing downloads Chromium. Then try `vendor/bin/pest --exclude-group=browser` and watch Playwright boot anyway — the group label filters tests, the suite split is what stops the files being included at all.
5. **Add a `RequiresDocker`-gated integration test.** Pick a repository backed by Eloquent, write an `@group integration` test against a real Postgres testcontainer using `fireflyConfigFor()`, and confirm `vendor/bin/pest` (no flags) skips it entirely while `vendor/bin/pest --group=integration` runs it for real.
6. **Watch `$forwardTo` matter.** Bind a `RecordingAuthenticationEvents` with no `$forwardTo` from `defineFireflyEnvironment()`, register an `#[AsEventListener]` on `InteractiveAuthenticationSuccessEvent` that writes a row, and sign in: confirm the spy records the event and the listener never ran. Then re-bind it wrapping the framework's own publisher and confirm both happen. Finally move the `instance()` call to *after* boot and confirm the spy records nothing at all — the binding moment is the whole trick.
7. **Reach past a gate two ways.** Take a controller test that gets a `401`, and make it pass twice: once with `#[WithMockUser(roles: ['ADMIN'])]` on the method, once with `withoutSecurity()`. Then put `#[WithMockUser]` on the class and a different one on a single method, and confirm which of the two the framework honours.

<span class="eyebrow">Part IV — Observability, Testing & Delivery · Chapter 12</span>

# Testing LaraFly Applications {.chtitle}

By the end of this chapter you will know `firefly/testing`'s two boot-harness families — `FireflyTestCase`/`FireflyDatabaseTestCase` for Testbench-style class tests, and `bootFireflyApp()`/`fireflyApplication()` for bare boots with no test framework glue at all — the ten recording doubles that let a test assert on what a Firefly port actually saw (something Laravel's own `Event::fake()`/`Bus::fake()` cannot do, because they have never heard of Firefly's ports), the five Firefly-flavored Pest expectations, the `WebSliceTestCase`/`DataSliceTestCase` pair that boots only the beans a test needs, and — because this book only teaches what is actually shipped — a real, honest limitation `samples/lumen`'s own tests ran into and how they worked around it.

!!! note "New term: slice test"
    A **slice test** boots only the narrow vertical of the framework a test actually exercises — the web pipeline over one controller, or the data pipeline over one repository — rather than the whole application. It is Spring Boot's `@WebMvcTest`/`@DataJpaTest` idea: faster boots, and a mis-wired slice fails immediately instead of quietly working by accident because some unrelated bean happened to be present too.

---

## The boot harness: `FireflyTestCase`

Every hand-rolled Firefly test base across the monorepo used to re-derive the same handful of boot-order rules by hand. `FireflyTestCase` absorbs them once, as a `Testbench\TestCase` subclass with exactly three hooks to override:

```php
abstract class FireflyTestCase extends TestCase
{
    /**
     * The Firefly capability providers this test needs, in registration order.
     * FireflyAutoConfigureServiceProvider is ALWAYS prepended by the harness — do NOT list it here.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function fireflyProviders(): array
    {
        return [];
    }

    /**
     * Eager, dot-keyed config seeded BEFORE boot so #[ConditionalOn*] passes (which scan at register
     * time) observe it. This is the seam for `firefly.scan.paths`, `firefly.<feature>.*` flags, etc.
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return [];
    }

    /**
     * Lazily-resolved bindings / manifest instances, bound AFTER config. Override to
     * $app->instance(SomeManifest::class, ...) or bind a port fake for the whole test class.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        // no-op by default
    }
}
```

The base class handles the parts every test used to get subtly wrong: `FireflyAutoConfigureServiceProvider` is *always* registered first (every capability provider's own `register()` assumes the kernel already exists), `configOverrides()` is applied before boot so a `#[ConditionalOnProperty]` scanning at register time actually observes it, and the log channel is switched to `errorlog` so a read-only sandbox never trips over a filesystem-backed logger. A minimal subclass needs nothing more than the one hook it actually uses:

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

```php
function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application { /* ... */ }
function bootFireflyApp(array $config = [], array $providers = [], array $bindings = [], array $needs = []): ApplicationContext { /* ... */ }
```

`$config` is the full config array; `$providers` register **after** `FireflyAutoConfigureServiceProvider`, which is always registered first regardless; `$bindings` are `$app->instance()` overrides bound *before* provider registration — the seam for handing in a port double; and `$needs` is a small "missing-bindings menu" (`'cache'`, `'validation'`, `'http'`) that binds a fresh, isolated fallback for a capability a bare boot would otherwise lack entirely:

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

`RecordingEventPublisher`, in full, shows the shape every double in the table follows — a real port implementation, plus a plain array that just remembers what happened:

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

    public function publishedOf(string $eventType): array
    {
        return array_values(array_filter($this->published, static fn (array $e): bool => $e['eventType'] === $eventType));
    }
}
```

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

```php
// tests/Pest.php
use Firefly\Testing\Pest\FireflyExpectations;

FireflyExpectations::register();
```

There are exactly **five**:

- **`toHavePublished(string $eventType, array $payloadContains = [])`** — on a `RecordingEventPublisher`; at least one published event of the given type whose payload contains the given key/value subset.
- **`toHaveHandledCommand(string $class)`** — on a `RecordingCommandBus`; at least one sent command is an instance of `$class`.
- **`toBeUp()`** — on a `Health` or a `HealthIndicator` (narrowed via `->health()` automatically); asserts `Status::Up`.
- **`toHaveRecordedMetric(string $name, array $tags = [])`** — on anything exposing `meters(): list<Meter>` (e.g. a `MeterRegistry`); at least one recorded meter with the given name and tag subset.
- **`toBeProblemDetails(int $status)`** — on a `TestResponse` (checks the `application/problem+json` content type and decodes the body) or a plain array; asserts the RFC-7807 shape has `type`/`title`/`status` keys and that `status` matches.

```php
<?php

use Firefly\Testing\Double\RecordingCommandBus;

// @phpstan-ignore method.notFound
expect($commandBus)->toHaveHandledCommand(PlaceOrder::class);
```

!!! note "The `@phpstan-ignore method.notFound` comments, explained"
    Pest installs these via `expect()->extend(...)` **at runtime** — PHPStan's static reflection of the vendor `Pest\Expectation` class has no way to see a method added this way, so every call is flagged `method.notFound` even though it genuinely exists once `tests/Pest.php` has run. Keep each custom expectation on its own `expect()` statement rather than chaining with `->and(...)`: chaining degrades the precisely-ignorable `method.notFound` into the unignorable `method.nonObject`.

For a plainer, PHPUnit-flavored assertion instead of the fluent expectations, two procedural helpers live alongside `bootFireflyApp()` in the same autoloaded `functions.php`:

```php
function assertEventPublished(object $publisher, string $eventType, array $payloadContains = []): void { /* ... */ }
function assertNoEventsPublished(object $publisher): void { /* ... */ }
```

!!! warning "A real, honest limitation: `toBeProblemDetails()` and the `type` field"
    `samples/lumen`'s own `Web\WalletRestTest.php` documents, in a code comment right next to the tests it affects, a genuine gap: `toBeProblemDetails()` asserts the response body has a `type` key, but the framework's real RFC-7807 renderer (`Firefly\Kernel\Error\ErrorResponse::fromException()`, rendered by `Firefly\Web\Exception\ProblemDetailsRenderer`) never populates `type` — it is an optional field only emitted when explicitly passed, which the exception-to-response path never does. Calling `toBeProblemDetails()` against a genuine `404`/`422`/`403`/`409` this framework actually produces fails, every time, for exactly that reason.

    This is why Lumen's own tests — like `firefly/web`'s own capstone test suite — assert the RFC-7807 fields directly instead:

    ```php
    it('returns RFC-7807 problem+json for an unknown wallet', function () {
        $this->getJson('/api/v1/wallets/nope/balance')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('title', 'Not Found');
    });
    ```

    A book that only teaches what genuinely works would be doing you a disservice by hiding this: `toBeProblemDetails()` is real, shipped, and exercised by the testing package's own suite against a body that *does* carry a `type` key — it simply is not the assertion Lumen reaches for against the framework's actual error responses, and now you know why, instead of hitting the same failure cold.

---

## Slice testing: `WebSliceTestCase` and `DataSliceTestCase`

A slice test boots only the beans a PSR-4 scan discovers for one narrow vertical, plus whatever explicit port overrides you supply — Spring's `@WebMvcTest`/`@DataJpaTest` idea, ported. Both bases expose a method, callable straight from a Pest closure test:

```php
abstract class WebSliceTestCase extends FireflyTestCase
{
    public function webSlice(array $scan = [], array $overrides = []): ApplicationContext { /* ... */ }
}

abstract class DataSliceTestCase extends FireflyDatabaseTestCase
{
    public function dataSlice(array $scan = [], array $overrides = []): ApplicationContext { /* ... */ }
}
```

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

```php
final class FixtureRegistry
{
    public function register(string $name, Closure $factory): self { /* ... */ }
    public function has(string $name): bool { /* ... */ }
    public function make(string $name, array $overrides = []): object { /* ... */ }
    public function load(string ...$names): array { /* ... */ }
}
```

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

```php
<?php

use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Firefly\Testing\Fixture\AggregateSeeder;

$publisher = new RecordingApplicationEventPublisher;
(new AggregateSeeder)->publishEvents($publisher, new WidgetCreated('a'), new WidgetCreated('b'));

expect($publisher->events)->toHaveCount(2);
```

`ListenerSpy` records arbitrary string values a listener or handler fixture saw, in call order — the one shared replacement for what used to be a hand-rolled `Spy` class in every package that needed one:

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

```php
function fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array { /* ... */ }
```

Feed the result straight into a `FireflyTestCase` subclass's `configOverrides()`, or set each key on the config repository from `defineFireflyEnvironment()`.

---

## Proof from Lumen: how the sample dogfoods it all

`samples/lumen` builds its own test base, `LumenTestCase`, on top of `FireflyDatabaseTestCase` — the pattern every chapter of this book has ultimately been resting on:

```php
abstract class LumenTestCase extends FireflyDatabaseTestCase
{
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

    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => $this->scanPaths(),
            'firefly.security.enabled' => true,
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
        ];
    }
}
```

Every one of Chapter 4 through Chapter 11's HTTP calls in this book — every `$this->postJson(...)`, every RFC-7807 assertion — runs against a `LumenTestCase` subclass. It deliberately binds the *real* `InMemoryEventBus` as the `EventPublisher` (never a `RecordingEventPublisher`), because Lumen's own tests need the domain-to-integration bridge from Chapter 9 to genuinely fire, not merely to be recorded as if it had.

!!! laravel "Laravel parity"
    Laravel's own test kit (`RefreshDatabase`, `Event::fake()`, `Bus::fake()`) is excellent for Laravel's own primitives, but it has no visibility into a Firefly port — a `CommandBus::send()` or an `EventPublisher::publish()` call is invisible to `Event::fake()`, because nothing about it goes through Laravel's own event dispatcher. `firefly/testing`'s recording doubles and Pest expectations are the parity layer: the same "arrange a fake, assert on what it saw" idiom you already know from Laravel, aimed at the ports this book's other chapters actually introduced.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `FireflyTestCase` | Testbench base; three hooks (`fireflyProviders`/`configOverrides`/`defineFireflyEnvironment`); config seeded before boot |
| `FireflyDatabaseTestCase` / `UsesSqliteMemory` | Adds a shared sqlite `:memory:` connection + `createSchema()` |
| `bootFireflyApp()` / `fireflyApplication()` | Bare, no-Testbench boots; a `$needs` menu fills in missing cache/validation/http fallbacks |
| 10 recording doubles | Real port implementations that record what they saw — the parity layer `Event::fake()` can't reach |
| 5 Pest expectations | `toHavePublished`, `toHaveHandledCommand`, `toBeUp`, `toHaveRecordedMetric`, `toBeProblemDetails` |
| `WebSliceTestCase` / `DataSliceTestCase` | Boot only a PSR-4-scanned slice + explicit overrides; per-test-class, fail-fast on data |
| `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]` | Class-attribute analog of the same three shapes, for hand-written test classes |
| `FixtureRegistry` / `AggregateSeeder` / `ListenerSpy` | Named fixtures; domain-event replay; a shared call-order spy |
| `RequiresDocker` / `fireflyConfigFor()` | `@group integration` opt-in; skip-not-fail without Docker; testcontainer → config mapping |

---

## Try it yourself {.exercises}

1. **Write a slice test for one of Lumen's own controllers.** Using `WebSliceTestCase`, scan only `Lumen\Web\` and confirm you can serve one wallet endpoint without booting CQRS, EDA, or security at all — then note which requests fail, and why, once you understand exactly which beans the slice left out.
2. **Reproduce the `toBeProblemDetails()` gap yourself.** Call it against a real `404` response from Lumen's own REST API and confirm it fails with the missing-`type`-key message this chapter described — then rewrite the same assertion the way `WalletRestTest.php` actually does it, and confirm that one passes.
3. **Add a `RequiresDocker`-gated integration test.** Pick a repository backed by Eloquent, write an `@group integration` test against a real Postgres testcontainer using `fireflyConfigFor()`, and confirm `vendor/bin/pest` (no flags) skips it entirely while `vendor/bin/pest --group=integration` runs it for real.

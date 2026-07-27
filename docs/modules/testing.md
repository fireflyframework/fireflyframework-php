# Testing

`firefly/testing` is LaraFly's first-party test-support kit — the Spring `spring-boot-test`/`@SpringBootTest`
analogue. It ships a boot harness that mirrors the real production boot path (`FireflyAutoConfigureServiceProvider`
first, config seeded eager, filesystem-free logging), sqlite/testcontainers database bases, web/data "slice"
builders that boot only the beans a test needs, recording doubles for every capability package's port, a set of
Firefly-flavored Pest expectations, and a small fixture layer. It is dev-scoped — `require-dev` only, no runtime
service provider — and every other package in the monorepo dogfoods it for its own test suite.

## The boot harnesses

There are two families, matching the milestone's Family A / Family B split:

- **`FireflyTestCase`** (`Firefly\Testing\FireflyTestCase`) — a Testbench `TestCase` subclass. Subclass it and
  override three hooks; the base absorbs AutoConfigure-first provider ordering, the eager config seed, and a
  filesystem-free log channel:

    - `fireflyProviders(): array` — the capability service providers this test needs, in registration order.
      `FireflyAutoConfigureServiceProvider` is **always** prepended by the harness; do not list it yourself.
    - `configOverrides(): array` — eager, dot-keyed config seeded **before** boot, so `#[ConditionalOn*]` (which
      scan at register time) observe it. This is the seam for `firefly.scan.paths`, `firefly.<feature>.*` flags,
      etc.
    - `defineFireflyEnvironment(Application $app): void` — bindings/manifests set **after** config, e.g.
      `$app->instance(SomeManifest::class, ...)` or a port fake for the whole test class.

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

  `FireflyTestCase` also exposes `app(): Application` (throws a `LogicException` if called before boot, instead of
  returning `null` silently), `fireflyContext(): ApplicationContext` (the booted boot-engine facade), and
  `responseBody(TestResponse $response): string` (reads the body via `baseResponse->getContent()`, since
  `TestResponse::streamedContent()` throws on a non-streamed response).

- **`bootFireflyApp()` / `fireflyApplication()`** (`Firefly\Testing\functions.php`, autoloaded via
  Composer's `files` autoload) — the Family B analogue for bare, no-Testbench boots:

  ```php
  function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application
  function bootFireflyApp(array $config = [], array $providers = [], array $bindings = [], array $needs = []): ApplicationContext
  ```

  - `$config` — full config array (defaults to `['firefly' => []]`).
  - `$providers` — service providers registered **after** `FireflyAutoConfigureServiceProvider`, which is always
    registered first.
  - `$bindings` — `$app->instance($abstract, $instance)` overrides bound before provider registration (e.g. port
    doubles).
  - `$needs` — a "missing-bindings menu": any of `'cache'`, `'validation'`, `'http'` binds a fresh, isolated
    fallback (`ArrayStore`-backed cache, a real `IlluminateValidator`, a real HTTP kernel) for a capability a bare
    boot would otherwise lack — overridable by an explicit `$bindings` entry for the same abstract.

  `bootFireflyApp()` is the same call, but returns the booted `ApplicationContext` instead of the raw `Application`:

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

## Database test cases

- **`FireflyDatabaseTestCase`** (`Firefly\Testing\FireflyDatabaseTestCase`) — a `FireflyTestCase` that binds a
  shared sqlite `:memory:` connection as the `testing` default and adds `createSchema(string $table, Closure(Blueprint): void $blueprint): void`, a thin wrapper over `Schema::create()`:

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

- **`UsesSqliteMemory`** (`Firefly\Testing\Concerns\UsesSqliteMemory`) — the trait `FireflyDatabaseTestCase` mixes
  in. Its one method, `defineSqliteMemory(Application $app): void`, sets `database.default` to `testing` and seeds
  `database.connections.testing` with the sqlite `:memory:` driver config. Mix it directly into your own base if
  you need the sqlite seed without the rest of `FireflyDatabaseTestCase`.

## Recording doubles

Every capability package's port gets a recording/stub double, so a test can assert on **what happened** without
Laravel's `Event::fake()`/`Bus::fake()` (which don't see Firefly's own ports):

| Port | Double (`Firefly\Testing\Double\*`) | Records |
|---|---|---|
| `Firefly\Eda\EventPublisher` | `RecordingEventPublisher` | `$published` — a list of `{destination, eventType, payload, headers}`; `publishedOf(string $eventType)` filters by type |
| `Firefly\Context\Event\ApplicationEventPublisher` | `RecordingApplicationEventPublisher` | `$events` — every published event object, in order; `ofType(string $class)` filters |
| `Firefly\Cqrs\Command\CommandBus` | `RecordingCommandBus` | `$sent`; `willReturn(string $commandClass, mixed $result)` programs a canned result; `handled(string $class)` filters |
| `Firefly\Cqrs\Query\QueryBus` | `StubQueryBus` | `$asked`; `willReturn(string $queryClass, mixed $result)` programs a canned result |
| `Firefly\Cqrs\Metrics\CqrsMetrics` | `RecordingCqrsMetrics` | `$commandSuccesses`/`$commandFailures`/`$querySuccesses`/`$queryFailures`, each `{command\|query, seconds}` |
| `Firefly\Cqrs\Event\CommandEventPublisher` | `RecordingCommandEventPublisher` | `$published` (list of `DomainEvent`); constructor takes an optional `?Throwable $throw` to exercise the bridge's failure path |
| `Firefly\Messaging\MessageBrokerPort` | `RecordingMessageBroker` | `$published` — a list of `{topic, value, key, headers}`; `publishedTo(string $topic)` filters |
| `Firefly\Scheduling\Lock\DistributedLock` | `RecordingDistributedLock` | `$acquired`/`$released`; constructor `(bool $available = true)`; `setAvailable(bool $available)` toggles whether `tryAcquire()` grants the lock |
| `Firefly\Actuator\Health\HealthIndicator` | `FakeHealthIndicator` | Defaults `Status::Up`; constructor takes a `Health`, `setHealth(Health $health)` reprograms it for DOWN/OUT_OF_SERVICE scenarios |
| `Firefly\Observability\Tracing\Tracer` | `RecordingTracer` | `$spans` — every traced span name, in call order; faithfully invokes and returns the traced callback (like `NoOpTracer`, but observable) |

```php
<?php

use Firefly\Testing\Double\RecordingEventPublisher;

$publisher = new RecordingEventPublisher;
$publisher->publish('accounts.events', 'AccountOpened', ['owner' => 'alice'], ['x' => '1']);

expect($publisher->published)->toHaveCount(1)
    ->and($publisher->published[0]['eventType'])->toBe('AccountOpened');
```

## Pest expectations

Registered once, at monorepo boot, by `Firefly\Testing\Pest\FireflyExpectations::register()` — called from the
root `tests/Pest.php`, the only Pest bootstrap file the monorepo loads:

```php
// tests/Pest.php
use Firefly\Testing\Pest\FireflyExpectations;

FireflyExpectations::register();
```

- **`toHavePublished(string $eventType, array $payloadContains = [])`** — on a `RecordingEventPublisher`; asserts at
  least one published event of the given type whose payload contains the given key/value subset.
- **`toHaveHandledCommand(string $class)`** — on a `RecordingCommandBus`; asserts at least one sent command is an
  instance of `$class`.
- **`toBeUp()`** — on a `Health` or a `HealthIndicator` (narrowed via `->health()` if needed); asserts
  `Status::Up`.
- **`toHaveRecordedMetric(string $name, array $tags = [])`** — on anything exposing `meters(): list<Meter>` (e.g. a
  `MeterRegistry`); asserts at least one recorded meter with the given name and tag subset.
- **`toBeProblemDetails(int $status)`** — on a `TestResponse` (checks the `application/problem+json` content type
  and decodes the JSON body) or a plain array; asserts the RFC-7807 shape has `type`/`title`/`status` keys and that
  `status` matches.

```php
<?php

use Firefly\Testing\Double\RecordingCommandBus;
use Firefly\Testing\Double\RecordingEventPublisher;

// @phpstan-ignore method.notFound
expect($commandBus)->toHaveHandledCommand(PlaceOrder::class);
// @phpstan-ignore method.notFound
expect($publisher)->toHavePublished('order.placed', payloadContains: ['id' => 'o-1']);
// @phpstan-ignore method.notFound
expect($healthIndicator->health())->toBeUp();
```

> **Note on the `@phpstan-ignore method.notFound` comments:** Pest registers `expect()->extend(...)` custom
> expectations at *runtime*. PHPStan's static reflection of the vendor `Pest\Expectation` class has no way to see
> a method added this way, so it flags every call as `method.notFound` even though the method genuinely exists at
> runtime (proven by the package's own test suite). Keep each custom expectation on its own `expect()` statement
> rather than chaining with `->and(...)` — chaining degrades the PHPStan error from the precisely-ignorable
> `method.notFound` to the unignorable `method.nonObject`.

For plain PHPUnit-style assertions instead of the fluent expectations, two procedural helpers are available
alongside `bootFireflyApp()`/`fireflyApplication()` in `Firefly\Testing\functions.php`:

```php
function assertEventPublished(object $publisher, string $eventType, array $payloadContains = []): void
function assertNoEventsPublished(object $publisher): void
```

```php
<?php

$publisher = new RecordingEventPublisher;
assertNoEventsPublished($publisher);

$publisher->publish('d', 'Thing', ['id' => 7]);
assertEventPublished($publisher, 'Thing', ['id' => 7]);
```

## Slice builders

Two "slice" bases boot **only** the beans a PSR-4 scan discovers, plus explicit port overrides — the Spring
`@WebMvcTest`/`@DataJpaTest` analogues:

- **`WebSliceTestCase`** (`Firefly\Testing\Slice\WebSliceTestCase`, extends `FireflyTestCase`) — boots the real
  Web + Validation pipeline (including `RouteWiringPass`) over only the sliced controllers, so `$this->get(...)`
  serves a real `TestResponse` against them.
- **`DataSliceTestCase`** (`Firefly\Testing\Slice\DataSliceTestCase`, extends `FireflyDatabaseTestCase`) — boots
  the real Data pipeline over only the sliced beans, then fail-fast resolves every scanned bean so a mis-wired
  slice fails immediately instead of silently.

Both expose a **method**, callable from a Pest closure test:

```php
public function webSlice(array $scan = [], array $overrides = []): ApplicationContext
public function dataSlice(array $scan = [], array $overrides = []): ApplicationContext
```

```php
<?php

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

```php
<?php

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

Both `webSlice()`/`dataSlice()` are **per-test-class**: calling one a second time with different arguments throws
a `LogicException`.

### `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]` attributes

`Firefly\Testing\Attributes\FireflyTest`, `WebSlice`, and `DataSlice` are **class-target attributes** — the
analog of the three bases/methods above for **hand-written, class-style tests** (a PHPUnit-style test class, not a
Pest closure file):

```php
public function __construct(
    public array $providers = [],
    public array $config = [],
) {}                                    // #[FireflyTest]

public function __construct(
    public array $scan = [],
    public array $overrides = [],
) {}                                    // #[WebSlice] and #[DataSlice] share this shape
```

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

> **Attributes vs. the method calls — when to use which.** `#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` are read
> by the base class's `setUp()` **before** the (one-and-only) Testbench boot runs, so they only work on a
> hand-written **class extending `FireflyTestCase`/`WebSliceTestCase`/`DataSliceTestCase`** — not on a Pest
> closure file. Pest closure tests (`it('...', function () { ... })`) call `$this->webSlice(...)` /
> `$this->dataSlice(...)` directly instead: the method internally calls `refreshApplication()` to re-boot with the
> slice values, since testbench already performed an empty first boot in `setUp()` before the closure body ever
> runs. Do not mix the two on the same test class.

## Fixture layer

- **`FixtureRegistry`** (`Firefly\Testing\Fixture\FixtureRegistry`) — a thin named-fixture registry over plain
  factory closures, the Firefly convenience atop Eloquent factories:

  ```php
  public function register(string $name, Closure $factory): self   // Closure(array<string,mixed>): object
  public function has(string $name): bool
  public function make(string $name, array $overrides = []): object
  public function load(string ...$names): array                    // list<object>
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

- **`AggregateSeeder`** (`Firefly\Testing\Fixture\AggregateSeeder`) — replays an aggregate's domain events through
  an `ApplicationEventPublisher` port:

  ```php
  public function publishEvents(ApplicationEventPublisher $publisher, object ...$events): void
  ```

  ```php
  <?php

  use Firefly\Testing\Double\RecordingApplicationEventPublisher;
  use Firefly\Testing\Fixture\AggregateSeeder;

  $publisher = new RecordingApplicationEventPublisher;
  (new AggregateSeeder)->publishEvents($publisher, new WidgetCreated('a'), new WidgetCreated('b'));

  expect($publisher->events)->toHaveCount(2);
  ```

- **`ListenerSpy`** (`Firefly\Testing\Fixture\ListenerSpy`) — records arbitrary string values a listener/handler
  fixture saw, in order. Replaces the per-package hand-rolled `Spy` classes:

  ```php
  public array $seen = [];
  public function record(string $value): void
  ```

  ```php
  <?php

  use Firefly\Testing\Fixture\ListenerSpy;

  $spy = new ListenerSpy;
  $spy->record('order.placed');
  $spy->record('order.shipped');

  expect($spy->seen)->toBe(['order.placed', 'order.shipped']);
  ```

## Boot-time footgun-killers: `FireflyBoot`

`Firefly\Testing\Boot\FireflyBoot` centralizes two boot-time gotchas the hand-rolled test bases used to
re-derive by hand:

```php
public static function makeConditionEvaluator(Config $config, Profiles $profiles = new Profiles([])): ConditionEvaluator
public static function stubScheduledManifest(Application $app): ScheduledManifest
```

- **`makeConditionEvaluator()`** — `ConditionEvaluator`'s constructor is arity-2 (`Config` + `Profiles`), but tests
  that never exercise `#[ConditionalOnProfile]` tend to forget the second argument. This helper defaults
  `$profiles` to an empty `Profiles([])` so the common case stays a 1-arg call.
- **`stubScheduledManifest()`** — the actuator/observability `ScheduledTasksEndpoint` singleton resolves a
  `ScheduledManifest` eagerly during container boot, even in slices that never touch scheduling. This binds +
  returns the canonical empty `new ScheduledManifest([])` stub so such a slice can still boot.

```php
<?php

use Firefly\Config\Config;
use Firefly\Testing\Boot\FireflyBoot;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

$config = new Config(new Repository(['firefly' => ['demo' => ['enabled' => true]]]));
$evaluator = FireflyBoot::makeConditionEvaluator($config);

$app = new Application;
$manifest = FireflyBoot::stubScheduledManifest($app);
```

## Testcontainers hook

For real-backend integration tests (Postgres, Kafka, etc.), see
[Integration Testing](integration-testing.md) — `firefly/testing` ships `RequiresDocker` +
`fireflyConfigFor()` to make Docker-backed tests opt-in and CI-safe.

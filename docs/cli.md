# CLI

`firefly/cli` is LaraFly's developer-experience console — the Spring Boot Maven/Gradle-plugin analogue, built as
a set of Artisan commands. It compiles the app for a zero-reflection boot (`firefly:cache`), introspects a booted
app in-process at the terminal (`firefly:about`/`:routes`/`:health`/`:metrics` — actuator-over-CLI, no HTTP
round-trip), scaffolds every framework stereotype (`make:firefly-*`), and thinly delegates to Laravel's own
`serve`/database commands (`firefly:serve`/`firefly:db`). New Deptrac `Cli` layer — depends on the rest of the
framework, depended on by nothing.

## `firefly:cache`

```
php artisan firefly:cache
```

The zero-reflection payoff: it runs every settled package's existing scanner → compiler pair over
`config('firefly.scan.paths')` (a PSR-4 map, e.g. `'App\\' => app_path()`) and writes the compiled app manifests
plus the `#[Transactional]` proxy classes into `bootstrap/cache/firefly/`:

```
bootstrap/cache/firefly/
├── component.php          # DI component manifest (container + context + autoconfigure)
├── context.php            # application-context manifest
├── config-properties.php  # #[ConfigProperties] DTOs
├── routes.php             # compiled route table
├── exception-handlers.php # #[ControllerAdvice]/#[ExceptionHandler] manifest
├── constraints.php        # validation constraint manifest
├── handlers.php           # #[CommandHandler]/#[QueryHandler] manifest
├── event-listeners.php    # #[EventListener] manifest
├── message-listeners.php  # #[MessageListener] manifest
├── scheduled.php          # #[Scheduled] manifest
├── security-methods.php   # #[PreAuthorize]/#[Secured]/#[RolesAllowed] manifest
├── transactional.php      # #[Transactional] method manifest
├── proxies.php            # FQCN => file classmap for the generated proxies
└── proxies/               # one generated proxy class file per #[Transactional] target
```

A `FireflyCacheServiceProvider` (auto-discovered with `firefly/cli`) `instance()`s these compiled manifests over
whatever the capability packages resolved, registers the `#[ConfigProperties]` bindings, and installs a
`spl_autoload_register` classmap loader for the proxy classes — all before any bean resolution runs, giving a fully
cached, reflection-free boot. Three config keys point the boot path at the cache:

```php
'firefly' => [
    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],
],
```

### Cached and uncached boots

Every manifest above is resolved by the same three-step convention, and `Firefly\Context\Scan\AppScan` is the
seam each capability package uses to do it:

1. the compiled artifact exists under `firefly.cache.path` → load it, zero reflection (production);
2. otherwise `firefly.scan.paths` is non-empty → scan those PSR-4 roots **in-process**, on every boot (development);
3. otherwise → an empty manifest, and boot still succeeds.

`FireflyAutoConfigureServiceProvider` has always done this for the component and context manifests (via
`component_manifest`/`context_manifest`). It is now also what routes, `#[ControllerAdvice]` handlers, CQRS handlers,
event and message listeners, scheduled tasks, validation constraints, method-security rules, `#[ConfigProperties]`
DTOs and the `#[Transactional]` manifest do — so an application that has never run `firefly:cache` behaves the same
as one that has, and pays a full reflection scan per boot for the privilege.

That is a change, not a restatement: **before it, step 2 did not exist.** Every capability bound an *empty*
manifest and only `firefly/cli`'s `FireflyCacheServiceProvider` ever replaced it, which made a `require-dev` tool
the sole owner of the loading half of the contract. An app that skipped the compile step — or that installed the
`firefly/firefly` metapackage, which did not require the CLI — booted with no routes (404 on everything it owned)
and, worse, with an empty method-security manifest: both enforcement sites read "no rule for this method" as ALLOW,
so `#[PreAuthorize]`, `#[Secured]` and `#[RolesAllowed]` all failed **open**. `firefly/cli` is
now part of the `firefly/firefly` metapackage, and `firefly.security.method.strict` (default `false`) makes the
strict reading available to anyone who wants a build that ships without a compiled manifest to refuse to boot
rather than run unprotected.

Compiling is still worth it — reflection-free boot is the point of `firefly:cache` — but it is now an optimisation
rather than a correctness requirement.

## `firefly:clear`

```
php artisan firefly:clear
```

The inverse of `firefly:cache` — recursively deletes `bootstrap/cache/firefly/` (or the configured
`firefly.cache.path`) and nothing else. Safe to run any time; a subsequent boot falls back to in-process scanning.

## Actuator-over-CLI: `firefly:about`, `firefly:routes`, `firefly:health`, `firefly:metrics`

These render M12 actuator/observability endpoint data at the terminal, in-process — no HTTP request is made. Each
resolves the compiled `ActuatorRegistry`, calls the matching endpoint's `handle()`, and prints its response;
none reimplement actuator logic.

```
php artisan firefly:about
```

Prints the version followed by the `info`, `env`, `beans`, `conditions`, `mappings`, and `scheduledtasks`
actuator endpoints in sequence — the full Spring Boot `--debug`/actuator introspection story at a glance.

```
php artisan firefly:routes
```

Renders the compiled route table (the `mappings` actuator endpoint).

```
php artisan firefly:health
```

Renders the aggregated health status (the `health` actuator endpoint).

```
php artisan firefly:metrics
```

Renders the observability metrics snapshot (the `metrics` actuator endpoint; requires `firefly/observability`).

Any of these commands prints a warning and exits successfully if the corresponding endpoint is disabled or not
wired (e.g. `firefly/observability` not installed for `firefly:metrics`).

## `make:firefly-*` generators

Pyfly's `generate` command family, one Artisan generator per stereotype:

| Command | Generates |
|---------|-----------|
| `make:firefly-controller` | A `#[RestController]` with a sample `#[GetMapping]` action, under `app/Http`. |
| `make:firefly-service` | A `#[Service]` bean. |
| `make:firefly-component` | A `#[Component]` bean. |
| `make:firefly-handler` | **Two files**: a `#[CommandHandler]` *and* the command class its `handle()` takes (`#[QueryHandler]` + query with `--query`). |
| `make:firefly-listener` | A `#[Component]` class with an `#[EventListener]` method, or a `#[MessageListener]` one with `--message`. |
| `make:firefly-entity` | A DDD entity extending `Firefly\Domain\Entity` (there is no `#[Entity]` attribute). |
| `make:firefly-repository` | A concrete `#[Repository]` class extending `Firefly\Data\Repository\EloquentRepository`, with a `$model` to repoint. |
| `make:firefly-config-properties` | A `#[ConfigProperties]`-bound configuration DTO. |

Three of those outputs are shaped by what the scanners actually accept, and it is worth knowing why:

- **The handler generator emits its message class too.** `HandlerScanner` infers a bare `#[CommandHandler]`'s
  message type from `handle()`'s sole parameter, and a builtin type (the old stub's `object $command`) cannot be
  resolved — it threw `CqrsConfigurationException` out of `firefly:cache`, aborting the *whole* compile. So the
  generated `handle()` takes a concrete class, and that class is written alongside it: `RegisterWidgetHandler` +
  `RegisterWidget`, `CountWidgetsHandler` + `CountWidgets`. A message file that already exists is left alone and
  reported, never overwritten. Nested names stay together (`make:firefly-handler Widget/RegisterWidgetHandler`
  puts both in the same sub-namespace).
- **The listener generator puts `#[Component]` on the class.** `#[EventListener]`/`#[MessageListener]` mark a
  method of a *bean*; without a stereotype `ComponentScanner::describe()` returns null, the class never reaches
  the component manifest, and the wiring pass's `$container->make()` falls through to Illuminate's reflective
  auto-build — a plain object outside Firefly's lifecycle, with no `#[Value]` injection, no post-processing and a
  new instance per delivery.
- **The repository generator emits a class, not an interface.** Nothing synthesises an implementation for a
  repository interface (there is no Spring-Data dynamic proxy here), so the old `interface X extends CrudRepository`
  was unresolvable by construction. The generated class is deliberately not `final`, because `firefly:cache` emits
  a `#[Transactional]` proxy that `extends` it.

```
php artisan make:firefly-controller GreetingController
php artisan make:firefly-service GreetingService
php artisan make:firefly-handler RegisterWidget
php artisan make:firefly-handler CountWidgets --query
php artisan make:firefly-listener WidgetEventListener
php artisan make:firefly-listener WidgetMessageListener --message
php artisan make:firefly-entity Widget
php artisan make:firefly-repository WidgetRepository
php artisan make:firefly-config-properties GreetingProperties
```

## Thin passthroughs: `firefly:serve`, `firefly:db`

```
php artisan firefly:serve {--host=127.0.0.1} {--port=8000}
```

Delegates to `artisan serve`, or to `octane:start` when `laravel/octane` is installed (probed via `class_exists()`
only — Octane stays an optional runtime dependency, never required by `firefly/cli`'s `composer.json`).

```
php artisan firefly:db {action=migrate}
```

Delegates to Laravel's own database commands: `migrate` (default), `db:seed` (`firefly:db seed`), or
`migrate:fresh` (`firefly:db fresh`). Neither command reimplements any Laravel behavior — both are thin
`$this->call(...)` passthroughs.

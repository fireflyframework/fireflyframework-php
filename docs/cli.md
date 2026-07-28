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

A `FireflyCacheServiceProvider` (auto-discovered) binds these compiled manifests over each capability's
`bound()`-guarded empty default, registers the `#[ConfigProperties]` bindings, and installs a `spl_autoload_register`
classmap loader for the proxy classes — all before any bean resolution runs, giving a fully cached, reflection-free
boot. Two config keys point the boot path at the cache:

```php
'firefly' => [
    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],
],
```

When `component_manifest`/`context_manifest` point at files that exist, `FireflyAutoConfigureServiceProvider` loads
them via `::load()` instead of scanning `firefly.scan.paths` in-process. Without a cache, the app still boots — via
the in-process scanner fallback — just without the zero-reflection guarantee.

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
| `make:firefly-handler` | A `#[CommandHandler]` by default, or a `#[QueryHandler]` with `--query`. |
| `make:firefly-listener` | An `#[EventListener]` method by default, or a `#[MessageListener]` with `--message`. |
| `make:firefly-entity` | A DDD entity extending `Firefly\Domain\Entity` (there is no `#[Entity]` attribute). |
| `make:firefly-repository` | A repository interface extending `Firefly\Data\Repository\CrudRepository`. |
| `make:firefly-config-properties` | A `#[ConfigProperties]`-bound configuration DTO. |

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

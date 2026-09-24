# CLI

`firefly/cli` is LaraFly's developer-experience console — the Spring Boot Maven/Gradle-plugin analogue, built as
a set of Artisan commands. It compiles the app for a zero-reflection boot (`firefly:cache`), introspects a booted
app in-process at the terminal (`firefly:about`/`:routes`/`:health`/`:metrics` — actuator-over-CLI, no HTTP
round-trip), generates the OAuth2 authorization server's signing key (`firefly:oauth2:keys`), scaffolds every
framework stereotype (`make:firefly-*`), and thinly delegates to Laravel's own `serve`/scheduler/database
commands (`firefly:serve`/`firefly:schedule`/`firefly:db`). Its own Deptrac `Cli` layer — depends on the rest of
the framework, depended on by nothing.

Four more `firefly:*` commands ship with the capability they belong to rather than with the console:
`firefly:management:serve` (`firefly/actuator`), `firefly:openapi` (`firefly/openapi`),
`firefly:eda:consume` (`firefly/eda`) and `firefly:outbox:relay` (`firefly/eda-postgres`). They are all
listed at the end of this page.

## `firefly:cache`

```
php artisan firefly:cache
```

The zero-reflection payoff: it runs every settled package's existing scanner → compiler pair over
`config('firefly.scan.paths')` (a PSR-4 map, e.g. `'App\\' => app_path()`) and writes fourteen compiled
artifacts — every manifest below, unconditionally, whether or not the application has anything to put in it —
plus one generated proxy class per planned bean, into `bootstrap/cache/firefly/`. It prints what it wrote:
`firefly:cache — wrote 14 manifest(s) + N proxy(ies) to <dir>`.

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
├── proxy-plan.php         # the compiled ProxyPlan: which beans get a proxy, and the advice each method runs
├── proxies.php            # FQCN => file classmap for the generated proxies
└── proxies/               # one generated proxy class file per PLANNED class
```

`proxies/` holds one file per class the *plan* claims, not per `#[Transactional]` class:
`ManifestCacheWriter::writeProxies()` asks the planner for `proxyMethods($plan)`, and the plan is built from
every `AdviceSource` — so a `#[Service]` carrying only `#[PreAuthorize]` is proxied exactly like a
`#[Transactional]` one. `proxy-plan.php` is what the cached boot reads (`DataAutoConfiguration::proxyPlan()`);
`transactional.php` is still written, and from here on only serves a cache compiled before the plan file
existed.

A `FireflyCacheServiceProvider` (auto-discovered with `firefly/cli`) `instance()`s these compiled manifests over
whatever the capability packages resolved, registers the `#[ConfigProperties]` bindings, and installs a
`spl_autoload_register` classmap loader for the proxy classes — all before any bean resolution runs, giving a fully
cached, reflection-free boot. Three config keys point the boot path at the cache:

<!-- source: skeleton/config/firefly.php -->

```php
'cache' => [
    'path' => base_path('bootstrap/cache/firefly'),
    'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
    'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
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

### When the cache names a class you deleted

Deleting a `#[Component]` and forgetting to recompile leaves `bootstrap/cache/firefly/component.php` naming a class
no autoloader can find. That used to be unrecoverable by any single command: the deleted class was still in the
manifest the container registrar read, so the interface it implemented was bound to a class that was not there, and
`firefly:cache` and `firefly:clear` — each of which has to boot the application before it can rewrite or delete the
manifest — both died with `Target class [...] does not exist`, pointing at a file you had already deleted, from
inside a bean you had not touched.

Those two commands now drop a manifest entry whose class cannot be found, and `firefly:cache` says which one:

```
php artisan firefly:cache
```

```
firefly:cache — wrote 14 manifest(s) + 3 proxy(ies) to bootstrap/cache/firefly
firefly:cache — skipped 1 stale manifest entry naming a class that no longer exists: App\Security\ControlPlaneJwksProvider
```

The manifest it writes no longer mentions the class, so the next run is an ordinary clean one and the line goes away.

**Only these two commands drop anything, and that is deliberate.** Under every other command the manifest is trusted
exactly as before, and a missing class still stops the boot. A class that has gone missing in a process about to
serve traffic is not a stale cache — it is a broken deployment, a truncated artifact or a classmap built from a
different tree — and quietly dropping the definition there would rebind the interface to whichever implementation
happened to survive, with nothing said anywhere. Only a MISSING class is ever tolerated: a class that exists and
cannot be constructed still fails fast, in a repair command as much as anywhere else.

## `firefly:clear`

```
php artisan firefly:clear
```

The inverse of `firefly:cache` — recursively deletes `bootstrap/cache/firefly/` (or the configured
`firefly.cache.path`) and nothing else. Safe to run any time; a subsequent boot falls back to in-process scanning.

## Actuator-over-CLI: `firefly:about`, `firefly:routes`, `firefly:health`, `firefly:metrics`

These render actuator and observability endpoint data at the terminal, in-process — no HTTP request is made.
Each resolves the compiled `ActuatorRegistry`, calls the matching endpoint's `handle()`, and prints its
response; none reimplement actuator logic.

```
php artisan firefly:about
```

Prints `LaraFly <version>` — `Firefly\Kernel\Version::VERSION` — and then the `info`, `env`, `beans`,
`conditions`, `mappings` and `scheduledtasks` endpoints in that order, each under a `# <id>` heading: the full
Spring Boot `--debug`/actuator introspection story at a glance.

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

## `firefly:management:serve`

Contributed by `firefly/actuator`, not by `firefly/cli` — the command is part of the management-port
capability rather than of the console. Its `$description` says what it is for: *Run a second dev listener for
the actuator on the management port (`firefly.management.server.port`).*

```bash
php artisan firefly:management:serve
```

Two options, both defaulted from configuration:

| Option | Default |
|---|---|
| `--host=` | Bind address; defaults to `firefly.management.server.address`, then `127.0.0.1`. |
| `--port=` | Listen port; defaults to `firefly.management.server.port`. |

`firefly.management.server.port` is only half a mechanism on its own: `ManagementPortGuard` makes the actuator
refuse the application port, and in production something else — a second PHP-FPM pool, a second container, a
proxy rule — has to answer on the management port. None of those exist on a laptop, so this command starts a
second `artisan serve` bound to the configured management address and port, alongside whichever server is
already serving the application. It delegates to `serve` and never to `octane:start` (unlike `firefly:serve`):
a low-traffic side channel does not need a second Octane supervisor.

It refuses three things rather than starting a listener that would mislead you: a `--port` that is not a TCP
port between 1 and 65535, no configured management port and no `--port`, and a management port equal to the
application port. It then prints the actuator's URL, the bind address, and the fact the feature is
one-directional — **the actuator is unreachable on the application port, but application routes still answer
on both**, because one `artisan serve` is one Laravel application. See
[Actuator](modules/actuator.md).

## `make:firefly-*` generators

The analogue of PyFly's `generate` family — one Artisan generator per stereotype:

| Command | Generates |
|---------|-----------|
| `make:firefly-controller` | **Two files**, under `app/Http`: a `#[RestController]` REST resource — `index`/`show`/`store`/`update`/`destroy` under one class-level `#[RequestMapping]` whose path is derived from the resource name (`OrderController` → `/orders`) — *and* the `#[Valid] #[RequestBody]` DTO its `store`/`update` bind. `--plain` writes the single-action shape instead, with no DTO. |
| `make:firefly-service` | A `#[Service]` bean. |
| `make:firefly-component` | A `#[Component]` bean. |
| `make:firefly-handler` | **Two files**: a `#[CommandHandler]` *and* the command class its `handle()` takes (`#[QueryHandler]` + query with `--query`). |
| `make:firefly-listener` | A `#[Component]` class with an `#[EventListener]` method, or a `#[MessageListener]` one with `--message`. |
| `make:firefly-entity` | A DDD entity extending `Firefly\Domain\Entity` (there is no `#[Entity]` attribute). |
| `make:firefly-repository` | A concrete `#[Repository]` class extending `Firefly\Data\Repository\EloquentRepository`, with a `$model` to repoint. |
| `make:firefly-config-properties` | A `#[ConfigProperties]`-bound configuration DTO. |

Four of those outputs are shaped by what the scanners actually accept, and it is worth knowing why:

- **The controller generator emits its request DTO too, and scaffolds the whole resource.** The old stub was
  one `index()` action mapped to `#[GetMapping('/{{ class }}')]` — the PHP class name substituted straight
  into a URL — so `make:firefly-controller OrderController` served `/OrderController`: capitalised, singular,
  carrying the word "Controller". Nobody ships that, so the first thing every developer did with the
  framework's own scaffold was delete it. What replaces it derives the collection path from the class name
  (`OrderController` → `/orders`, `OrderItemController` → `/order-items`, `PersonController` → `/people`) and
  declares it once as a class-level `#[RequestMapping]`, so each action carries only its own suffix and verb.
  The DTO is written alongside it for the handler's reason, only stronger: the controller names the DTO type
  in its `store`/`update` signatures and `firefly:cache` reflects *every* controller parameter to compile its
  binding plan, so a controller emitted without its DTO would not merely be incomplete — it would be a file
  PHP cannot load, poisoning the next compile. A DTO that already exists is reused and reported, never
  overwritten. `--plain` is the escape hatch for the endpoints that are not a collection of things — webhook
  receivers, probes, reports, RPC-shaped action verbs — and gives the one-action shape with no DTO.
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
php artisan make:firefly-controller OrderController
php artisan make:firefly-controller GreetingController --plain
php artisan make:firefly-service GreetingService
php artisan make:firefly-handler RegisterWidget
php artisan make:firefly-handler CountWidgets --query
php artisan make:firefly-listener WidgetEventListener
php artisan make:firefly-listener WidgetMessageListener --message
php artisan make:firefly-entity Widget
php artisan make:firefly-repository WidgetRepository
php artisan make:firefly-config-properties GreetingProperties
```

## `firefly:oauth2:keys`

The one command `firefly/security-oauth2-server` cannot start without: it generates the private key the
authorization server signs tokens with, and prints the two lines you need next.

```bash
php artisan firefly:oauth2:keys
php artisan firefly:oauth2:keys --algorithm=ES256 --out=storage/oauth2/es256.pem
php artisan firefly:oauth2:keys --print
```

| Option | Default | What it does |
|---|---|---|
| `--algorithm=` | `RS256` | `RS256` (an RSA key) or `ES256` (an EC P-256 key). |
| `--bits=` | `2048` | The RSA modulus size. Ignored for `ES256`. |
| `--out=` | *(empty)* | Where to write the PEM. Empty means `storage/oauth2/private.pem`. |
| `--force` | off | Overwrite an existing file. |
| `--print` | off | Print the PEM to the console instead of writing a file. |

The file is written with owner-only permissions (`0600`, in a directory created `0700`), and **an existing
file is never overwritten without `--force`**: a key replaced by accident invalidates every token in flight,
so the refusal says exactly that and exits non-zero. On success the command prints the env variable to set —
`FIREFLY_OAUTH2_SERVER_SIGNING_KEY`, which feeds `firefly.security.oauth2.server.jwt.signing_key` — and the
`kid` the JWKS endpoint will publish for that key.

Rotation is not a re-run of this command on its own: move the old key to `jwt.previous_keys` (its public half
is enough) so tokens already issued keep verifying, then generate the new one here. The full procedure is in
[OAuth2 Authorization Server](modules/security-oauth2-server.md).

## Thin passthroughs: `firefly:serve`, `firefly:schedule`, `firefly:db`

```
php artisan firefly:serve {--host=127.0.0.1} {--port=8000}
```

Delegates to `artisan serve`, or to `octane:start` when `laravel/octane` is installed (probed via `class_exists()`
only — Octane stays an optional runtime dependency, never required by `firefly/cli`'s `composer.json`).

```
php artisan firefly:schedule {--once}
```

The companion to `firefly:serve` for the other process an application needs: a `#[Scheduled]` method fires
only under Laravel's `schedule:work` (or a cron-driven `schedule:run`), and nothing starts either by itself —
a developer who has written `#[Scheduled(fixedRate: '60s')]` on the method that advances every workflow is
otherwise testing a product whose clock is stopped. The command prints every scheduled task the
`ScheduledManifest` holds (the compiled artifact or the in-process scan, whichever this boot uses) with the
cadence it will *really* run at (`Cadence::describe()`, the same table `ScheduleWiringPass` wires with, so
`'7s'` is reported as `every 10 seconds`), its lock and its timezone, then delegates to `schedule:work`; with
`--once` it delegates to a single `schedule:run` instead, for a cron entry or a health probe. A task missing
from the listing was never compiled, and that is visible on line one.

```
php artisan firefly:db {action=migrate}
```

Delegates to Laravel's own database commands: `migrate` (default), `db:seed` (`firefly:db seed`), or
`migrate:fresh` (`firefly:db fresh`). Neither command reimplements any Laravel behavior — both are thin
`$this->call(...)` passthroughs.

## Commands contributed by other packages

`firefly/cli` is not the only package that registers Artisan commands; a capability package ships its own where the
command is part of that capability rather than of the console.

| Command | Package | What it does |
|---|---|---|
| `firefly:openapi` | `firefly/openapi` | Writes the generated OpenAPI 3.1 document to `--output=<file>` (parent directories are created, and a summary line is printed) or **raw** to stdout. Stdout is written with Symfony's `OUTPUT_RAW` so the bytes are exactly the document's — `php artisan firefly:openapi \| <client-generator>` is the intended use — which is also why the confirmation line prints only in `--output` mode. See [OpenAPI](modules/openapi.md#php-artisan-fireflyopenapi). |
| `firefly:management:serve` | `firefly/actuator` | Runs a second dev listener for the actuator on the management port. `--host=`, `--port=`. See [`firefly:management:serve`](#fireflymanagementserve) above. |
| `firefly:eda:consume` | `firefly/eda` | Runs the configured broker `EventConsumer`, dispatching to `#[EventListener]` handlers until stopped. `--destination=*` (repeatable; overrides `firefly.eda.destinations`), `--max-messages=`, `--time-limit=`, `--sleep=0` (idle ms between empty polls), `--poll-timeout=5000` (block ms per poll). The bound destinations are echoed at startup, so a worker subscribed to nothing is visible on line one. Errors out when no broker `EventConsumer` is bound — the `memory` and `queue` providers have no consumer loop, and `queue` uses `php artisan queue:work`. See [EDA](modules/eda.md). |
| `firefly:outbox:relay` | `firefly/eda-postgres` | **Optional.** Forwards committed `firefly_eda_outbox` rows to the downstream broker named by `firefly.eda.postgres.relay.downstream_provider` (claim → publish → mark `PUBLISHED`/`FAILED`). `--max-messages=`, `--time-limit=`, `--sleep=1` (seconds between empty batches), `--batch-size=50`. In-process delivery is `firefly:eda:consume`'s job, not this command's, so an app that only needs `#[EventListener]` handlers never runs it; an unset or misconfigured `downstream_provider` fails loudly before a single row is claimed. See [EDA Brokers](modules/eda-brokers.md). |

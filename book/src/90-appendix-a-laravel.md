<span class="eyebrow">Appendix A</span>

# Laravel → LaraFly Cheat-Sheet {.chtitle}

LaraFly is **additive**: it layers a Spring-Boot-shaped application model on top of Laravel 13 — it never forks, wraps, or replaces the framework underneath. Every LaraFly capability is a normal Composer package registering normal Laravel service providers; a LaraFly app is still, in every respect a Laravel developer would recognize, a Laravel app. This appendix is a quick-reference map from the Laravel idiom you already know to the LaraFly concept this book introduced it alongside, with the chapter that covers it in depth.

## At a glance

| Concern | Raw Laravel | LaraFly | Chapter |
|---|---|---|---|
| Entry point | Service providers registered in `bootstrap/providers.php`, wired by hand | The same providers, plus auto-discovered `AutoConfiguration` classes assembled by a kernel-decided `BootPass` pipeline | 2 |
| Dependency injection | `app()->bind()`/`app()->singleton()` in a provider's `register()` | `#[Component]`/`#[Service]`/`#[Repository]`/`#[Configuration]` stereotypes on the class itself; a compiled component scan resolves constructor dependencies | 2 |
| Configuration | `config('mail.host')` (array access, untyped) | `#[ConfigProperties]` DTOs bound from a config subtree — typed, fail-fast on a missing/mismatched key | 3 |
| HTTP routing | `routes/web.php`/`routes/api.php` route files | `#[RestController]` + verb attributes (`#[GetMapping]`, …), compiled to a `RouteManifest`, still dispatched through native Laravel routes | 4 |
| Validation | `FormRequest::rules()` (array rules) | `#[Valid]` parameter interception over a Bean-Validation-style constraint model, still backed by Laravel's validator | 4 |
| Persistence | Eloquent models + query builder directly in application code | The same Eloquent underneath a `CrudRepository` port/adapter — application code depends on an interface, never on Eloquent directly | 5 |
| Domain modeling | Eloquent models carry both data and behavior | `Firefly\Domain\Entity`/aggregates with no framework dependency; Eloquent lives only in the adapter | 6 |
| Transactions | `DB::transaction(fn () => …)` (closure-scoped) | `#[Transactional]` on a class/method — declarative propagation/isolation/rollback rules, a generated proxy drives `beginTransaction`/`commit`/`rollBack` | 9 |
| Events | `Event::listen()`/`#[AsEventListener]`, in-process only | Two distinct surfaces: the in-process bus (`#[AsEventListener]`) **and** a broker-backed EDA bus (`#[EventListener]`) | 8 |
| CQRS | Not a first-class concept — a Laravel "command" usually means an Artisan console command | `CommandBus`/`QueryBus` with a bounded pipeline (validate → authorize → invoke → metrics) and `#[CommandHandler]`/`#[QueryHandler]` | 7 |
| Security | Auth guards + route middleware (`->middleware('auth')`, `Gate::allows()`) | Spring-Security-6-shaped `SecurityContext` + deny-by-default `HttpSecurity` URL DSL + method security (`#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]`) | 10 |
| Operations | Nothing built in — health checks and metrics are typically bespoke or a package | `firefly/actuator` (health/info/env/beans/conditions/mappings/loggers/scheduledtasks) + `firefly/observability` (Prometheus/Micrometer-JSON metrics) | 11 |
| Testing | `RefreshDatabase`, `Event::fake()`, `Bus::fake()` — see Laravel's own primitives only | `FireflyTestCase`/`FireflyDatabaseTestCase`, recording doubles for Firefly's own ports, Firefly-flavored Pest expectations | 12 |
| Scaffolding | `php artisan make:controller`/`make:model` | `php artisan make:firefly-controller`/`make:firefly-handler`/… — one generator per stereotype | 13 |
| Boot performance | Laravel's own config/route caching (`config:cache`, `route:cache`) | `php artisan firefly:cache` — one command compiles every Firefly manifest **and** generates `#[Transactional]` proxies for a zero-reflection boot | 13 |

---

## Entry point: service providers vs. auto-configuration

Laravel boots by registering whatever service providers `bootstrap/providers.php` lists, in the order they appear (or, for package-discovered providers, roughly alphabetically). LaraFly's `firefly/context` sits on top of that: every capability package's provider still exists and is still discovered by Laravel exactly the same way — but its `register()` method does nothing except *buffer* its `BootPass` contributions into a `PendingBootPasses` collector. The shared `FireflyKernel` drains that buffer once it is actually resolved and runs each phase (scanning, condition evaluation, bean registration, wiring passes) in a kernel-decided order — so which provider Laravel happened to instantiate first is irrelevant.

## Dependency injection: `app()->bind()` vs. stereotypes

Laravel's container is powerful but explicit — a binding lives in a provider, separate from the class it binds:

```php
final class GreetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GreetingService::class, fn () => new GreetingService('Hello'));
    }
}
```

LaraFly's `firefly/container` layers PHP 8 attributes onto `Illuminate\Container` instead — mark the class itself with `#[Component]` (or `#[Service]`/`#[Repository]`/`#[Configuration]`, all specializations of it) and a compiled component scan registers it, resolving constructor dependencies by type:

```php
#[Service]
final readonly class GreetingService
{
    public function __construct(private string $greeting = 'Hello') {}
}
```

Nothing stops you from also using `app()->bind()` directly — the two coexist, and Chapter 2 showed you exactly when each is the better fit.

## Configuration: `config()` vs. `#[ConfigProperties]`

`config('mail.port')` is untyped array access — a typo or a missing key returns `null` silently. `firefly/config` adds `#[ConfigProperties('mail')]` DTOs that bind a whole config subtree onto a plain readonly class at once, typed and fail-fast on a missing required key. Laravel's `config/*.php` files remain the single source of truth; LaraFly reads them, it does not replace them.

## Transactions: `DB::transaction()` vs. `#[Transactional]`

`DB::transaction(fn () => …)` scopes a transaction to a closure — propagation and rollback rules are whatever you write inline. `#[Transactional]` declares propagation (`REQUIRED`, `REQUIRES_NEW`, `NESTED`, …), isolation, read-only, and rollback/no-rollback exception lists on the class or method itself; a proxy generated at scan time (Chapter 9) drives the boundary through manual `beginTransaction()`/`commit()`/`rollBack()`, so a caught exception can still be committed when it matches `noRollbackFor`.

## Security: middleware vs. deny-by-default `HttpSecurity`

Laravel's default posture is permissive: a route is public unless you attach `->middleware('auth')` or an ability check. `firefly/security` inverts that — the `HttpSecurity` URL DSL builds an ordered rule list and denies anything unmatched (401 anonymous, 403 authenticated), the same deny-by-default model as Spring Security. Method security (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) is enforced at the CQRS bus and the controller dispatcher via a closed-whitelist expression evaluator — no `eval()` anywhere in the chain (Chapter 10). Laravel's own auth guards and middleware still work underneath; `firefly/security` is a stricter layer atop them, not a fork.

## Operations: actuator & observability

Laravel ships no health-check or metrics endpoint out of the box — most teams either hand-roll one or reach for a package. `firefly/actuator` (health/info/env/beans/conditions/mappings/loggers/scheduledtasks under `/actuator`, secured entirely by ordinary `HttpSecurity` config) and `firefly/observability` (a first-party Prometheus-0.0.4 + Micrometer-JSON `/metrics` exposition) are the Spring Boot Actuator and Micrometer analogues, respectively — both opt-in Composer packages, both secure-by-default (Chapter 11).

## Scaffolding and boot performance: `make:*`/`config:cache` vs. `make:firefly-*`/`firefly:cache`

Laravel's own `make:controller`/`make:model` generators and its `config:cache`/`route:cache` boot-performance commands have direct LaraFly analogues, extended to cover every framework stereotype rather than only Eloquent models and routes: `make:firefly-*` (eight generators, one per stereotype) and `firefly:cache` (which compiles *every* Firefly manifest — components, routes, CQRS handlers, event listeners, scheduled tasks, security rules, transactional metadata — plus generates the `#[Transactional]` proxy classes, in one command) — see Chapter 13.

!!! laravel "Laravel parity"
    Every row on this page is meant to be read in one direction only: LaraFly never asks you to give up a Laravel idiom, it asks you to additionally reach for a more structured one where the codebase benefits from it. A LaraFly project can freely mix `app()->bind()` and `#[Service]`, a plain `routes/api.php` route and a `#[RestController]`, in the same codebase — nothing in this book's samples requires an all-or-nothing migration.

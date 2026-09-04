# Laravel Comparison

LaraFly is **additive**: it layers a Spring-Boot-shaped application model on top of Laravel 13 — it never
forks, wraps, or replaces the framework underneath. Every LaraFly capability is a normal Composer package
registering normal Laravel service providers; a LaraFly app is still, in every respect a Laravel developer
would recognize, a Laravel app. This page is the same kind of comparison [pyfly's Spring Boot
comparison](https://github.com/fireflyframework/fireflyframework-pyfly/blob/main/docs/spring-comparison.md)
draws for Python, mapped onto Laravel instead.

## At a glance

| Concern | Raw Laravel | LaraFly |
|---|---|---|
| Entry point | Service providers registered in `bootstrap/providers.php`, wired by hand | The same providers, plus auto-discovered `AutoConfiguration` classes assembled by a kernel-decided `BootPass` pipeline |
| Dependency injection | `app()->bind()`/`app()->singleton()` in a provider's `register()` | `#[Component]`/`#[Service]`/`#[Repository]`/`#[Configuration]` stereotypes on the class itself; compiled component scan resolves constructor dependencies |
| Configuration | `config('mail.host')` (array access, untyped) | `#[ConfigProperties]` DTOs bound from a config subtree — typed, fail-fast on a missing/mismatched key |
| HTTP routing | `routes/web.php`/`routes/api.php` route files | `#[RestController]` (JSON) / `#[Controller]` (HTML) + verb attributes (`#[GetMapping]`, …), compiled to a `RouteManifest`, still dispatched through native Laravel routes |
| Validation | `FormRequest::rules()` (array rules) | `#[Valid]` parameter interception over a Bean-Validation-style constraint model, still backed by Laravel's validator |
| Transactions | `DB::transaction(fn () => …)` (closure-scoped) | `#[Transactional]` on a class/method — declarative propagation/isolation/rollback rules, manual `beginTransaction`/`commit`/`rollBack` under the hood |
| Events | `Event::listen()` / `#[AsEventListener]`-style Laravel listeners, in-process only | Two distinct surfaces: the in-process bus (`#[AsEventListener]`) **and** a broker-backed EDA bus (`#[EventListener]`) — see below |
| CQRS | Not a first-class concept — Laravel has no command/query bus | `CommandBus`/`QueryBus` with a bounded pipeline (validate → authorize → invoke → metrics) and `#[CommandHandler]`/`#[QueryHandler]` |
| Security | Auth guards + route middleware (`->middleware('auth')`, `Gate::allows()`) | Spring-Security-6-shaped `SecurityContext` + deny-by-default `HttpSecurity` URL DSL + method security (`#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]`) |
| Operations | Nothing built in — health checks and metrics are typically bespoke or a package | `firefly/actuator` (health/info/env/beans/mappings) + `firefly/observability` (Prometheus/Micrometer-JSON metrics), both Spring Boot Actuator/Micrometer analogues |

## Entry point: service providers vs. auto-configuration

Laravel boots by registering whatever service providers `bootstrap/providers.php` lists, in the order they
appear (or, for package-discovered providers, roughly alphabetically). LaraFly's `firefly/context` sits on
top of that: every capability package's provider still exists and is still discovered by Laravel exactly the
same way — but its `register()` method does nothing except *buffer* its `BootPass` contributions into a
`PendingBootPasses` collector. The shared `FireflyKernel` drains that buffer once it is actually resolved and
runs each phase (scanning, condition evaluation, bean registration, wiring passes) in a kernel-decided order —
so which provider Laravel happened to instantiate first is irrelevant. See
[Architecture](architecture.md#the-boot-pipeline) for the full pipeline diagram.

## Dependency injection: `app()->bind()` vs. stereotypes

Laravel's container is powerful but explicit — a binding lives in a provider, separate from the class it
binds. LaraFly's `firefly/container` layers PHP 8 attributes onto `Illuminate\Container`: mark the class
itself with `#[Component]` (or `#[Service]`/`#[Repository]`/`#[Configuration]`, all specializations of it)
and a compiled component scan registers it, resolves constructor dependencies by type, and honors
`#[Primary]`/`#[Qualifier]`/`#[Order]` for multi-implementation ports — see
[Dependency Injection](modules/dependency-injection.md). Nothing stops you from also using `app()->bind()`
directly; the two coexist.

## Configuration: `config()` vs. `#[ConfigProperties]`

`config('mail.port')` is untyped array access — a typo or a missing key returns `null` silently. LaraFly's
`firefly/config` adds a fail-fast typed accessor (`$config->int('mail.port', 25)`, throws on a required key
that's absent or the wrong type) and `#[ConfigProperties('mail')]` DTOs that bind a whole config subtree onto
a plain readonly class at once — see [Configuration](modules/configuration.md). Laravel's `config/*.php`
files remain the source of truth; LaraFly reads them, it doesn't replace them.

## HTTP: route files vs. `#[RestController]`

Laravel routes live in `routes/*.php`, separate from the controller class. `firefly/web`'s
`#[RestController]` + `#[GetMapping]`/`#[PostMapping]`/etc. attributes put the route on the controller
method itself; a `RouteScanner` compiles them into a `RouteManifest` at cache time (or scans in-process when
there is no cache), and that manifest is what actually registers native Laravel routes at boot — there is no
custom dispatch mechanism underneath. `#[Controller]` is the HTML sibling: same routing, but a returned
`View`/`ModelAndView`/`Htmlable` renders as `text/html` instead of negotiating to JSON. See
[Web Layer](modules/web.md).

## Validation: `FormRequest` vs. `#[Valid]`

A Laravel `FormRequest::rules()` returns an array of string/rule-object rules, evaluated when the request is
resolved. `firefly/validation`'s `#[Valid]` (paired with `firefly/web`'s `#[RequestBody]`) intercepts a
method parameter and runs it through the same validator — the difference is where the rule set lives (a
`validate()` call or a rule class, not a `rules()` array method) and that failures render as RFC-7807
`ProblemDetails` instead of Laravel's default redirect/JSON-errors response. See
[Validation](modules/validation.md).

## Data & transactions: `DB::transaction()` vs. `#[Transactional]`

`DB::transaction(fn () => …)` scopes a transaction to a closure — propagation and rollback rules are
whatever you write inline. `firefly/data`'s `#[Transactional]` attribute (a Spring `@Transactional` analog)
declares propagation (`REQUIRED`, `REQUIRES_NEW`, `NESTED`, …), isolation, read-only, and
rollback/no-rollback exception lists on the class or method itself; a proxy generated at scan time drives
the boundary through manual `beginTransaction()`/`commit()`/`rollBack()` so a caught exception can still be
committed when it matches `noRollbackFor`. See [Transactions](modules/transactional.md).

## Events: one Laravel surface vs. two LaraFly surfaces

Laravel has one event mechanism: `Event::dispatch()`/listeners, in-process, synchronous by default. LaraFly
keeps that surface (`#[AsEventListener]`, from `firefly/context`) **and** adds a second, unrelated one:
`firefly/eda`'s broker-backed bus, where `#[EventListener]` subscribes to an event-type pattern
(`'user.*'`) rather than a PHP class, and delivery can be in-memory, queued (async, cross-process), or — via
the SP-4 broker adapters — RabbitMQ/Postgres/Kafka. These are deliberately **not the same thing**; see
[Event-Driven Architecture § Two event surfaces — not one](modules/eda.md#two-event-surfaces-not-one) for
the full distinction, and [CQRS](modules/cqrs.md) for how a committed domain event bridges onto the EDA bus
as an integration event.

## CQRS: a concept Laravel doesn't have

Laravel has no built-in command/query bus — a "command" in Laravel usually just means an Artisan console
command. `firefly/cqrs` adds a genuine CQRS mediator: `CommandBus::send()`/`QueryBus::ask()`, each running a
bounded pipeline (correlate → validate → authorize → invoke → metrics, plus a cache stage on the query side),
with handlers discovered via `#[CommandHandler]`/`#[QueryHandler]`. A `#[Transactional]` command handler gets
transaction semantics for free from the `firefly/data` proxy — the CQRS layer writes no interception code of
its own. See [CQRS (Command/Query)](modules/cqrs.md).

## Security: middleware vs. deny-by-default `HttpSecurity`

Laravel's default posture is permissive-by-default: a route is public unless you attach `->middleware('auth')`
or an ability check. `firefly/security` inverts that: the `HttpSecurity` URL DSL builds an ordered rule list
and denies anything unmatched (401 anonymous, 403 authenticated) — the same deny-by-default model as Spring
Security. On top of that, method security (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) is enforced at
the CQRS bus and the controller dispatcher via a whitelist expression evaluator (no `eval`), and a first-party
`SecurityContext`/`Authentication` principal model backs both JWT and OAuth2-resource-server authentication.
Laravel's own auth guards and middleware still work underneath — `firefly/security` is a stricter layer atop
them, not a fork. See [Security](modules/security.md).

## Operations: actuator & observability

Laravel ships no health-check or metrics endpoint out of the box — most teams either hand-roll one or reach
for a package. `firefly/actuator` (health/info/env/beans/conditions/mappings/loggers/scheduledtasks under
`/actuator`, secured entirely by ordinary `HttpSecurity` config) and `firefly/observability` (a first-party
Prometheus-0.0.4 + Micrometer-JSON `/metrics` exposition, HTTP auto-instrumentation, and the real
`CqrsMetrics` recorder) are the Spring Boot Actuator and Micrometer analogues, respectively — both opt-in
Composer packages, both secure-by-default. See [Actuator](modules/actuator.md) and
[Observability](modules/observability.md).

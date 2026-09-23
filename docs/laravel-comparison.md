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
| Interactive login | `Auth::routes()`, Breeze or Fortify — a scaffold copied into your application, which you own and maintain from then on | A `SecurityContextPersistenceFilter` that loads the context from the session, a `FormLoginFilter` on `/login`, the framework's own login page, a `LogoutFilter`, remember-me, and an entry point that negotiates a redirect against a 401 |
| Signing in with an external provider | Socialite — an OAuth2 client you drive from a controller you write | `firefly/security-oauth2-client`: OIDC login as a filter pair, provider presets, discovery, PKCE, id-token validation, an `OidcUser` injected into your action, RP-initiated logout, client credentials and `Http::oauth2Client()` |
| Being the provider | Passport for full OAuth2, Sanctum for first-party tokens | `firefly/security-oauth2-server`: registered clients in memory or Eloquent, `/oauth2/authorize` with PKCE and a consent page, `/oauth2/token` with three grants, introspection, revocation, userinfo, JWKS, both `.well-known` documents, RS256/ES256 keys from `firefly:oauth2:keys`, rotated through `jwt.previous_keys` |
| Distributed tracing | Nothing built in; Telescope is local-only and is not a trace | A `Tracer`/`Span` port with an OpenTelemetry adapter, a W3C `traceparent` continued at the server filter and injected on the `Http` client and both CQRS buses; an event published through the in-memory or queue bus carries it in the envelope headers, and every delivery is traced, a broker's included |
| Structured logging | Monolog, configured per channel by hand | `firefly.logging.structured.format` = `json`/`ecs`/`logstash`, with the correlation, request, trace and span ids attached by processors on every channel the framework resolves |
| Repository vocabulary | Eloquent query-builder calls, written out per use case | Spring Data's: derived queries, `#[Query]`, query by example, `#[Modifying]`, `#[Projection]`, `#[Lock]`, `#[EntityGraph]`, `Page`/`Slice`/`Pageable`/`Sort`, and a typed `DataAccessException` family instead of a raw `QueryException` |
| Browser testing | Dusk — a real browser against a separately served application | Pest 4 + Playwright over the Testbench-booted skeleton in-process (`composer test:browser`), with the same SQLite database and the same container |

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
Security. On top of that, method security (`#[PreAuthorize]`, `#[PostAuthorize]`, `#[PreFilter]`,
`#[PostFilter]`, `#[Secured]`, `#[RolesAllowed]`) rides the same compiled interceptor chain `#[Transactional]`
uses, so it holds on **any** stereotyped bean — a `#[Service]`, a `#[CommandHandler]`, a `#[Repository]` —
wherever that bean is called from, and additionally at the controller dispatcher and the CQRS bus. The
expression evaluator is a closed whitelist tokenizer with no `eval`. Underneath it all, one first-party
`SecurityContext`/`Authentication` principal model backs every mechanism: session-persisted form login, HTTP
Basic, remember-me, JWT, the OAuth2 resource server, and OpenID Connect login. Laravel's own auth guards and
middleware still work underneath — `firefly/security` is a stricter layer atop them, not a fork. See
[Security](modules/security.md).

## Login: a scaffold you own vs. a filter you configure

**In Laravel**, interactive login is a starter kit. Breeze, Jetstream or Fortify copy controllers, routes,
Blade views and form-request classes into your application; from that moment they are your code, and the
next security fix in the upstream kit is something you read about and port yourself:

<!-- illustrative: the plain-Laravel equivalent, which this repository does not contain -->

```php
// routes/auth.php, as a starter kit writes it into your application
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
```

**In LaraFly**, login is filters that already exist plus a configuration block. `SecurityContextPersistenceFilter`
(`#[Order(-94)]`) loads the `SecurityContext` from the session and saves it after the response;
`FormLoginFilter` (`-92`) handles `POST /login` — CSRF first, then the `AuthenticationManager`;
`LogoutFilter` (`-93`) handles `POST /logout`; `RememberMeAuthenticationFilter` (`-83`) restores a session
from the cookie. The login *page* is the framework's own Blade view unless you point `view` at yours, and
the reference configuration ships the whole block commented out at its defaults:

<!-- source: skeleton/config/firefly.php -->

```php
'form_login' => [
    'enabled' => env('FIREFLY_SECURITY_FORM_LOGIN_ENABLED', false),
    // 'login_page' => '/login',
    // 'login_processing_url' => '/login',
    // 'username_parameter' => 'username',
    // 'password_parameter' => 'password',
    // 'default_success_url' => '/',
    // 'always_use_default_success_url' => false,
    // 'failure_url' => '/login?error',
    // 'view' => 'auth.login',
],
```

There is no generated code to maintain, and no route file to keep in step: an upgrade to
`firefly/security` upgrades the login flow. The trade is the usual one — you configure rather than edit, so
a behaviour the settings do not expose is a feature request rather than a two-line patch in your own
controller. See [Security](modules/security.md).

## OAuth2: two packages, two directions

**In Laravel**, the two directions come from unrelated packages with unrelated shapes. Socialite makes you
an OAuth2 *client*, but it hands you a user object and leaves the session, the account linking and the
redirect handling to a controller you write. Passport makes you an authorization *server*; Sanctum issues
first-party tokens that are not OAuth2 at all:

<!-- illustrative: the plain-Laravel equivalent, which this repository does not contain -->

```php
// routes/web.php — Socialite gives you the round trip; the rest is yours
Route::get('/auth/google/redirect', fn () => Socialite::driver('google')->redirect());

Route::get('/auth/google/callback', function () {
    $external = Socialite::driver('google')->user();

    $user = User::updateOrCreate(
        ['email' => $external->getEmail()],
        ['name' => $external->getName(), 'google_id' => $external->getId()],
    );

    Auth::login($user, remember: true);

    return redirect('/dashboard');
});
```

**In LaraFly** both directions are the same shape as everything else: a package you install, a block of
configuration, and filters that join the chain. `firefly/security-oauth2-client` adds
`OAuth2AuthorizationRequestRedirectFilter` (`-89`) and `OAuth2LoginAuthenticationFilter` (`-88`), so
`/oauth2/authorization/{registrationId}` and `/login/oauth2/code/{registrationId}` are served without a
route or a controller of yours existing; PKCE, the single-use `state` and `nonce`, and id-token validation
against the issuer's JWKS are the filters' job. What reaches your code is a principal:

<!-- illustrative: an application's own controller, which by definition is not a file in this repository -->

```php
<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Security\Core\Attributes\AuthenticationPrincipal;
use Firefly\Security\OAuth2\Client\User\OidcUser;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class ProfileController
{
    /** @return array{sub: string, email: string|null, name: string|null} */
    #[GetMapping('/api/me')]
    public function me(#[AuthenticationPrincipal] OidcUser $user): array
    {
        return [
            'sub' => $user->getSubject(),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
        ];
    }
}
```

`firefly/security-oauth2-server` points the same protocol the other way and adds
`OAuth2AuthorizationServerFilter` (`-82`), serving `/oauth2/authorize` with PKCE and a consent page,
`/oauth2/token` with three grants, introspection, revocation, userinfo, JWKS and both `.well-known`
documents. The two packages are independent installs — an application can be a relying party, an
authorization server, both, or neither. See [OAuth2 Client](modules/security-oauth2-client.md) and
[OAuth2 Authorization Server](modules/security-oauth2-server.md).

## Operations: actuator & observability

Laravel ships no health-check or metrics endpoint out of the box — most teams either hand-roll one or reach
for a package. `firefly/actuator` mounts `health`, `info`, `env`, `beans`, `conditions`, `mappings`,
`loggers`, `scheduledtasks`, `caches` and `configprops` under `/actuator`, secured entirely by ordinary
`HttpSecurity` configuration and — apart from `health` and `info`, the two the default include list already
names — unexposed (404) until added to `firefly.management.endpoints.web.exposure.include`;
`firefly/observability` adds `metrics`, `prometheus`, `process` and `httpexchanges` — a first-party
Prometheus-0.0.4 + Micrometer-JSON exposition, HTTP auto-instrumentation, and the real `CqrsMetrics` recorder.
They are the Spring Boot Actuator and Micrometer analogues respectively, both opt-in Composer packages, both
secure-by-default, and `firefly.management.server.port` puts the whole surface on a second listener. See
[Actuator](modules/actuator.md) and [Observability](modules/observability.md).

## Tracing and logs: Telescope vs. a propagated trace

**In Laravel**, there is no distributed tracing. Telescope is an excellent local debugger — requests,
queries, jobs and mails for *one* application, stored in that application's own database — but it does not
propagate anything, so two Laravel services handling the same user action record two unrelated stories. The
correlation id that would tie them together is something every team eventually writes by hand:

<!-- illustrative: the plain-Laravel equivalent, which this repository does not contain -->

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AddRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::withContext(['request_id' => $request->header('X-Request-Id') ?? (string) Str::uuid()]);

        return $next($request);
    }
}
```

**In LaraFly** that is the framework's job, and it reaches further than one process. `TracingFilter`
(`#[Order(-110)]`) extracts the inbound W3C `traceparent` and starts a `SERVER` span with it as the parent,
so a request that arrives inside a trace continues it; the ids are published to Laravel's `Context` and
`Request::$attributes`; and the same context is carried across five boundaries — inbound HTTP, an `INTERNAL`
span per CQRS message, a `PRODUCER` span stamping the header into the envelope on the in-memory and queue
buses, a `CONSUMER` span on every delivery (a broker's included, through the shared `SubscriberRegistrySink`;
the broker publishers do not stamp the header yet — see [Known-latent](modules/tracing.md#known-latent)), and
a `CLIENT` span on every outbound `Http` call, which injects the header again. Logging is the same story told
in a second place: `TraceContextLogProcessor` puts `trace_id` and `span_id` on every record
beside the correlation and request ids, and one key chooses the line format:

<!-- source: skeleton/config/firefly.php -->

```php
'logging' => [
    'structured' => [

        // …

        'format' => env('FIREFLY_LOG_FORMAT', ''),

        // …

        // 'channels' => ['stack', 'stderr'],
    ],
],
```

`json` is Monolog's `JsonFormatter`, `ecs` is Elastic Common Schema 8, `logstash` is Monolog's
`LogstashFormatter`, and `''` keeps Laravel's plain-text lines — anything else refuses to boot rather than
logging in a format nobody asked for. None of it costs anything until you opt in: the shipped `Tracer` is a
`NoOpTracer`, and the OpenTelemetry adapter binds itself only when the SDK is installed. See
[Tracing](modules/tracing.md) and [Logging](modules/logging.md).

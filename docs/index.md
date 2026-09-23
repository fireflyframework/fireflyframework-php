![LaraFly](assets/larafly-banner.svg)

# LaraFly

**LaraFly** is the PHP edition of the Firefly Framework — Spring Boot's cohesion, native to Laravel 13. It
layers dependency injection with stereotypes, conditional auto-configuration, hexagonal ports & adapters,
CQRS, event-driven architecture, first-party security including both halves of OAuth2, and a project CLI
directly onto Laravel's own runtime. Nothing is forked or wrapped: a LaraFly app is, in every respect a
Laravel developer would recognize, still a Laravel app — it just boots like a Spring Boot one.

The whole framework is one monorepo of small, independently-installable Composer packages, wired together by
a single zero-reflection boot pipeline: a component scan compiles to a cached manifest once, and every
request after that runs against plain PHP arrays — no runtime reflection on the hot path.

## Start here

<div class="lf-cards" markdown>

<div class="lf-card" markdown>
<span class="lf-card-title">Install</span>
[Installation](installation.md)
<span class="lf-card-note">Requirements, the `firefly new` quick install, and `composer create-project` on its own when you would rather not install a global binary.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">First run</span>
[Getting Started](getting-started.md)
<span class="lf-card-note">Boot the skeleton, read the `#[Controller]`/`#[RestController]`/`#[Service]` slice it generated, and — if you are starting from an app you already have — pull the whole family in with one `composer require`.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Build something</span>
[Tutorial](tutorial.md) · [Tutorial (Español)](tutorial.es.md)
<span class="lf-card-note">A hand-built, 12-step walkthrough from `composer create-project` to a `#[Repository]`/`#[Valid]`/CQRS/`#[EventListener]` feature slice, with a curl'd expected output at every step.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Understand it</span>
[Architecture](architecture.md)
<span class="lf-card-note">The hexagonal design, the phased boot pipeline, and where the compiled manifests come from.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Coming from Laravel</span>
[Laravel Comparison](laravel-comparison.md)
<span class="lf-card-note">Concept mapping for developers who already know Laravel: what stays, what is added, and what a stereotype replaces.</span>
</div>

</div>

## Quickstart

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:cache   # compile the zero-reflection boot manifests
php artisan serve
```

See [Installation](installation.md) for requirements and manual setup, and
[Getting Started](getting-started.md) for a walkthrough of the generated app.

## Why LaraFly?

- **Attribute-driven DI & auto-configuration** — `#[Service]`, `#[Repository]`, `#[Configuration]` classes are
  discovered by a compiled scan; install a capability package and its defaults wire themselves up, your own
  beans always win.
- **Hexagonal by construction** — every subsystem exposes a port and one or more adapters, with architectural
  direction enforced by Deptrac, not convention alone.
- **Declarative transactions & CQRS** — `#[Transactional]` demarcates boundaries at scan time; one generated
  proxy per bean runs the whole advice chain, security *before* transaction (`Advice` order 100 against
  1000), so a refusal is thrown before a transaction is ever opened. A `CommandBus`/`QueryBus` mediator
  dispatches commands and queries, with domain events bridged onto the event-transport bus after commit.
- **Event-driven, with real brokers** — an in-memory default plus RabbitMQ, Postgres LISTEN/NOTIFY, and Kafka
  adapters behind one `EventPublisher` port.
- **Secure by default** — a session-persisted `SecurityContext`, form login on the framework's own page, HTTP
  Basic, remember-me, logout, deny-by-default [`HttpSecurity`](modules/security.md) URL rules, and method
  security (`#[PreAuthorize]`, `#[PostAuthorize]`, `#[PreFilter]`, `#[PostFilter]`) enforced on **any**
  stereotyped bean through that same shared interceptor chain.
- **Both halves of OAuth2** — sign in with an external provider ([OIDC login](modules/security-oauth2-client.md)
  with provider presets, discovery, PKCE, id-token validation, RP-initiated logout, client credentials and
  `Http::oauth2Client()`), or **be** the provider
  ([an authorization server](modules/security-oauth2-server.md) with registered clients, `/oauth2/authorize`
  with PKCE and a consent page, `/oauth2/token` with three grants, introspection, revocation, `/userinfo`,
  JWKS and both `.well-known` documents).
- **Traced and logged like a service, not a script** — a `Tracer`/`Span` port with an
  [OpenTelemetry adapter](modules/tracing.md), a W3C `traceparent` continued at the server filter and carried
  on through the `Http` client, both CQRS buses and the in-memory and queue event buses, and
  [structured logging](modules/logging.md) in `json`, `ecs` or `logstash` carrying the same ids.
- **Production-ready out of the box** — an Actuator surface (health/info/beans) and a Prometheus/Micrometer-style
  metrics core, both secured by the same config as everything else, plus a server-rendered
  [admin dashboard](modules/admin.md) over them with a drawn [bean graph](modules/bean-graph.md), and an
  opt-in, off-by-default [data browser](modules/data-browser.md) over your own repositories, with filtering,
  full CRUD, relations you can walk and a drawn [entity map](modules/admin.md#the-entity-map).
- **An API document that cannot drift** — [`firefly/openapi`](modules/openapi.md) generates OpenAPI 3.1 from the
  same compiled manifests the dispatcher and the validator read, and serves the official Swagger UI from your own
  origin — no annotation dialect, no npm, no CDN.
- **A first-party test kit** — a boot harness, recording doubles for every port, and `#[WebSlice]`/`#[DataSlice]`
  test slices, dogfooded across the framework's own suites — which include a Pest 4 + Playwright suite driving
  real Chromium over the skeleton's own pages; that one is the framework's, and
  [Contributing](contributing.md) has what it covers and how to run it.

## The modules

Every capability above ships as a package you install on its own. The [module index](modules.md) lays all 32
guides out by concern, with a line on each saying what it is for, and the same grouping is the site's
**Modules** tab.

| Group | Guides |
|---|---|
| **Foundation** | [Error Handling](modules/error-handling.md) · [Dependency Injection](modules/dependency-injection.md) · [Configuration](modules/configuration.md) · [Application Context](modules/context.md) · [Auto-Configuration](modules/starters.md) · [Validation](modules/validation.md) |
| **Web & API** | [Web Layer](modules/web.md) · [Web Filters](modules/web-filters.md) · [OpenAPI](modules/openapi.md) |
| **Resilience & Scheduling** | [Resilience](modules/resilience.md) · [Scheduling](modules/scheduling.md) |
| **Data & Domain** | [Domain (DDD)](modules/domain.md) · [Data & Repositories](modules/data.md) · [Relational Data](modules/data-relational.md) · [Transactions](modules/transactional.md) |
| **Eventing & Messaging** | [EDA](modules/eda.md) · [EDA Brokers](modules/eda-brokers.md) · [Messaging](modules/messaging.md) |
| **CQRS** | [Command/Query](modules/cqrs.md) |
| **Security** | [Security](modules/security.md) · [OAuth2 Client](modules/security-oauth2-client.md) · [OAuth2 Authorization Server](modules/security-oauth2-server.md) |
| **Operations** | [Actuator](modules/actuator.md) · [Observability](modules/observability.md) · [Tracing](modules/tracing.md) · [Logging](modules/logging.md) · [Admin Dashboard](modules/admin.md) · [Bean Graph](modules/bean-graph.md) · [Data Browser](modules/data-browser.md) |
| **Testing** | [Testing](modules/testing.md) · [Integration Testing](modules/integration-testing.md) |
| **Tooling** | [Installer](modules/installer.md) |

## See it running

Want to see it all working together? The
[Lumen sample](https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen) is a
runnable digital-wallet & ledger vertical slice exercising `#[Transactional]`, CQRS, domain events over EDA,
method security, and a REST layer with RFC-7807 problem-details. The guided, book-style *LaraFly by Example*
book — 15 chapters plus appendices, bilingual (English + Spanish), building this exact sample — is available
in [`book/`](https://github.com/fireflyframework/fireflyframework-php/tree/main/book).

## Quick Links

- **CLI commands:** [CLI Reference](cli.md)
- **Releases & versioning:** [Versioning](versioning.md) · [Publishing](publishing.md)
- **Contributing to the monorepo:** [Contributing](contributing.md)
- **Every module guide, grouped:** [Modules](modules.md)

---

Apache-2.0 © Firefly Software Solutions Inc.

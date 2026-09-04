![LaraFly](assets/larafly-banner.svg)

# LaraFly

**LaraFly** is the PHP edition of the Firefly Framework — Spring Boot's cohesion, native to Laravel 13. It
layers dependency injection with stereotypes, conditional auto-configuration, hexagonal ports & adapters,
CQRS, event-driven architecture, first-party security, and a project CLI directly onto Laravel's own
runtime. Nothing is forked or wrapped: a LaraFly app is, in every respect a Laravel developer would
recognize, still a Laravel app — it just boots like a Spring Boot one.

The whole framework is one monorepo of small, independently-installable Composer packages, wired together by
a single zero-reflection boot pipeline: a component scan compiles to a cached manifest once, and every
request after that runs against plain PHP arrays — no runtime reflection on the hot path.

## Why LaraFly?

- **Attribute-driven DI & auto-configuration** — `#[Service]`, `#[Repository]`, `#[Configuration]` classes are
  discovered by a compiled scan; install a capability package and its defaults wire themselves up, your own
  beans always win.
- **Hexagonal by construction** — every subsystem exposes a port and one or more adapters, with architectural
  direction enforced by Deptrac, not convention alone.
- **Declarative transactions & CQRS** — `#[Transactional]` demarcates boundaries at scan time; a
  `CommandBus`/`QueryBus` mediator dispatches commands and queries, with domain events bridged onto the
  event-transport bus after commit.
- **Event-driven, with real brokers** — an in-memory default plus RabbitMQ, Postgres LISTEN/NOTIFY, and Kafka
  adapters behind one `EventPublisher` port.
- **Secure by default** — a Spring-Security-6-shaped principal model, deny-by-default `HttpSecurity` URL DSL,
  and method security (`#[PreAuthorize]`) enforced with no proxy magic.
- **Production-ready out of the box** — an Actuator surface (health/info/beans) and a Prometheus/Micrometer-style
  metrics core, both secured by the same config as everything else, plus a server-rendered
  [admin dashboard](modules/admin.md) over them with a drawn [bean graph](modules/bean-graph.md), and an
  opt-in, off-by-default [data browser](modules/data-browser.md) over your own repositories — shipping today as
  a library, with its dashboard page still to land.
- **An API document that cannot drift** — [`firefly/openapi`](modules/openapi.md) generates OpenAPI 3.1 from the
  same compiled manifests the dispatcher and the validator read, and serves the official Swagger UI from your own
  origin — no annotation dialect, no npm, no CDN.
- **A first-party test kit** — a boot harness, recording doubles for every port, and web/data test-slice
  builders, dogfooded across the framework's own test suite.

## Quickstart

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:cache   # compile the zero-reflection boot manifests
php artisan serve
```

See [Installation](installation.md) for requirements and manual setup, and
[Getting Started](getting-started.md) for a walkthrough of the generated app.

## Documentation Map

| Start here | |
|---|---|
| [Installation](installation.md) | Requirements and how to stand up a new app |
| [Getting Started](getting-started.md) | Boot the skeleton, write your first controller/service |
| [Tutorial](tutorial.md) | A hand-built, 12-step walkthrough building a `#[Repository]`/`#[Valid]`/CQRS/`#[EventListener]` feature |
| [Architecture](architecture.md) | The hexagonal design and the boot pipeline |
| [Laravel Comparison](laravel-comparison.md) | Concept mapping for developers coming from plain Laravel |

Module guides are grouped by concern under [`modules/`](modules/error-handling.md):

| Group | Guides |
|---|---|
| **Foundation** | [Error Handling](modules/error-handling.md) · [Dependency Injection](modules/dependency-injection.md) · [Configuration](modules/configuration.md) · [Application Context](modules/context.md) · [Auto-Configuration](modules/starters.md) · [Validation](modules/validation.md) |
| **Web & API** | [Web Layer](modules/web.md) · [Web Filters](modules/web-filters.md) · [OpenAPI](modules/openapi.md) |
| **Resilience & Scheduling** | [Resilience](modules/resilience.md) · [Scheduling](modules/scheduling.md) |
| **Data & Domain** | [Domain (DDD)](modules/domain.md) · [Data & Repositories](modules/data.md) · [Relational Data](modules/data-relational.md) · [Transactions](modules/transactional.md) |
| **Eventing & Messaging** | [EDA](modules/eda.md) · [EDA Brokers](modules/eda-brokers.md) · [Messaging](modules/messaging.md) |
| **CQRS** | [Command/Query](modules/cqrs.md) |
| **Security** | [Security](modules/security.md) |
| **Operations** | [Actuator](modules/actuator.md) · [Observability](modules/observability.md) · [Admin Dashboard](modules/admin.md) · [Bean Graph](modules/bean-graph.md) · [Data Browser](modules/data-browser.md) |
| **Testing** | [Testing](modules/testing.md) · [Integration Testing](modules/integration-testing.md) |
| **Tooling** | [Installer](modules/installer.md) |

New to LaraFly? Follow the [Tutorial](tutorial.md) — a hand-built, 12-step walkthrough from
`composer create-project` to a `#[Repository]`/`#[Valid]`/CQRS/`#[EventListener]` feature slice, with a
curl'd expected output at every step.

Want to see it all running together? The
[Lumen sample](https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen) is a
runnable digital-wallet & ledger vertical slice exercising `#[Transactional]`, CQRS, domain events over EDA,
method security, and a REST layer with RFC-7807 problem-details. The guided, book-style *LaraFly by Example*
book — 13 chapters plus appendices, bilingual (English + Spanish), building this exact sample — is available
in [`book/`](https://github.com/fireflyframework/fireflyframework-php/tree/main/book).

## Quick Links

- **CLI commands:** [CLI Reference](cli.md)
- **Releases & versioning:** [Versioning](versioning.md) · [Publishing](publishing.md)
- **Contributing to the monorepo:** [Contributing](contributing.md)
- **Full table of contents:** browse
  [`docs/README.md`](https://github.com/fireflyframework/fireflyframework-php/blob/main/docs/README.md) on
  GitHub

---

Apache-2.0 © Firefly Software Solutions Inc.

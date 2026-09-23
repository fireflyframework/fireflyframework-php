<p align="center">
  <img src="assets/larafly-banner.svg" alt="LaraFly — Firefly Framework for PHP" width="100%">
</p>

<p align="center">
  <strong>LaraFly Documentation</strong>
</p>

<p align="center">
  <em>Everything you need to build production-grade applications with LaraFly — Spring Boot's cohesion, native to Laravel 13.</em>
</p>

---

## Getting Started

| Guide | Description |
|-------|-------------|
| [Introduction](index.md) | What LaraFly is, the pitch, and a map of the docs |
| [Installation](installation.md) | Requirements, `composer create-project firefly/skeleton`, and manual install |
| [Getting Started](getting-started.md) | Boot the `firefly/skeleton` template, write your first `#[RestController]`/`#[Service]`, run `firefly:cache` |
| [Architecture](architecture.md) | The hexagonal design, the boot pipeline, and how the Deptrac layers fit together |
| [Tutorial](tutorial.md) | A hand-built, 12-step walkthrough — from `composer create-project` to a `#[Repository]`/`#[Valid]`/CQRS/`#[EventListener]` feature slice |
| [Lumen Sample](../samples/lumen/) | A runnable digital-wallet & ledger sample exercising `#[Transactional]`, CQRS, domain events over EDA, method security, and a REST layer with RFC-7807 problem-details |

---

## Module Guides

Every module guide lives under [`modules/`](modules/), in the ten groups `mkdocs.yml`'s navigation and the
site's own [Modules](modules.md) landing page use. All 32 are listed here, and neither the list nor the
number is maintained on trust: `tests/ModuleDocumentationTest.php` fails the build if a guide is missing
from the navigation, from the table below, from the root `README.md`'s table, from `docs/index.md` or from
the landing page, and `tests/SiteNavigationTest.php` reads that number off `docs/modules/*.md` itself, so a
thirty-third guide turns this page red rather than quietly making it wrong.

### Foundation

| Guide | Description |
|-------|-------------|
| [Error Handling](modules/error-handling.md) | `firefly/kernel`'s product-agnostic exception hierarchy and HTTP status mapping |
| [Dependency Injection](modules/dependency-injection.md) | `firefly/container` — attributes, component scan → compiled manifest, container resolution |
| [Configuration](modules/configuration.md) | `firefly/config` — profiles, typed config accessor, `#[ConfigProperties]`, `#[Value]` |
| [Application Context](modules/context.md) | `firefly/context` — the `FireflyKernel` boot pipeline, conditions, lifecycle callbacks |
| [Auto-Configuration & Starters](modules/starters.md) | `firefly/autoconfigure` — install a package, get sensible defaults, your own beans win |
| [Validation](modules/validation.md) | `firefly/validation` — constraint attributes behind `#[Valid]`, and the Spring-shaped problem document a failure renders |

### Web & API

| Guide | Description |
|-------|-------------|
| [Web Layer](modules/web.md) | `firefly/web` — `#[RestController]`/`#[Controller]` routing, parameter binding, JSON + HTML negotiation, RFC-7807 error rendering |
| [Web Filters](modules/web-filters.md) | An ordered `WebFilter` chain bridged onto Laravel's own middleware pipeline |
| [OpenAPI](modules/openapi.md) | `firefly/openapi` — an OpenAPI 3.1 document generated from the compiled manifests, `firefly:openapi`, and the official Swagger UI served from your own origin |

### Resilience & Scheduling

| Guide | Description |
|-------|-------------|
| [Resilience](modules/resilience.md) | `firefly/resilience` — Retry, CircuitBreaker, RateLimiter, Bulkhead, TimeLimiter, Fallback |
| [Scheduling](modules/scheduling.md) | `firefly/scheduling` — `#[Scheduled]` on Laravel's own scheduler, with distributed-lock guard |

### Data & Domain

| Guide | Description |
|-------|-------------|
| [Domain (DDD)](modules/domain.md) | `firefly/domain` — `Entity`, `ValueObject`, `AggregateRoot`, `DomainEvent`; zero reflection |
| [Data & Repositories](modules/data.md) | `firefly/data` — CRUD/paging ports, derived queries, `#[Query]`, query by example, `Specification`s, the `DataAccessException` family |
| [Relational Data](modules/data-relational.md) | `EloquentRepository` — the Eloquent-backed base every repository extends |
| [Transactions](modules/transactional.md) | `#[Transactional]` — declarative transaction demarcation over manual `DB::beginTransaction()` |

### Eventing & Messaging

| Guide | Description |
|-------|-------------|
| [Event-Driven Architecture](modules/eda.md) | `firefly/eda` — broker-backed `EventPublisher`, envelopes, retry/DLQ, in-memory + queue adapters |
| [EDA Brokers](modules/eda-brokers.md) | Real broker adapters behind the `EventPublisher` port — RabbitMQ, Postgres LISTEN/NOTIFY, Kafka |
| [Messaging](modules/messaging.md) | `firefly/messaging` — the lower-level raw-bytes `MessageBrokerPort`, `#[MessageListener]` |

### CQRS

| Guide | Description |
|-------|-------------|
| [CQRS (Command/Query)](modules/cqrs.md) | `firefly/cqrs` — the CommandBus/QueryBus mediator and the domain→integration-event bridge |

### Security

| Guide | Description |
|-------|-------------|
| [Security](modules/security.md) | `firefly/security` — Spring-Security-6-shaped principal model, session-persisted context, form login, HTTP Basic, JWT, deny-by-default authZ and method security |
| [OAuth2 Client](modules/security-oauth2-client.md) | `firefly/security-oauth2-client` — Spring's `oauth2Login()`/`oauth2Client()`: provider registrations, OIDC login with PKCE, and an authorized-client manager |
| [OAuth2 Authorization Server](modules/security-oauth2-server.md) | `firefly/security-oauth2-server` — an OAuth 2.1 / OIDC provider inside your application: codes, tokens, JWKS, introspection, revocation, consent |

### Operations

| Guide | Description |
|-------|-------------|
| [Actuator](modules/actuator.md) | `firefly/actuator` — health/info/beans endpoints, the Spring-Boot-Actuator analogue |
| [Observability](modules/observability.md) | `firefly/observability` — the `MeterRegistry`, Prometheus/Micrometer-JSON exposition, CQRS metrics |
| [Tracing](modules/tracing.md) | The OpenTelemetry-shaped `Tracer`/`Span` port, W3C `traceparent` propagation across HTTP, CQRS and EDA |
| [Logging](modules/logging.md) | Correlation, request, trace and span ids on every record, and JSON/ECS/Logstash structured output |
| [Admin Dashboard](modules/admin.md) | `firefly/admin` — the browser dashboard over the actuator; reads its endpoints in-process, so its own URL is the security boundary |
| [Bean Graph](modules/bean-graph.md) | The dashboard's drawn dependency graph — components, `#[Bean]` products and `#[ConfigProperties]` DTOs as nodes, interface-resolved edges, longest-path layering, cycle reporting |
| [Data Browser](modules/data-browser.md) | A Django-style database browser over `CrudRepository` beans — **off by default**, writes behind a second gate, with filtering, paging, relations you can walk, and an entity map |

### Testing

| Guide | Description |
|-------|-------------|
| [Testing](modules/testing.md) | `firefly/testing` — the boot harness, recording doubles, web/data test-slice builders |
| [Integration Testing](modules/integration-testing.md) | Opt-in tests against real backends (Postgres, Kafka), excluded from the default gate |

### Tooling

| Guide | Description |
|-------|-------------|
| [Installer](modules/installer.md) | `firefly/installer` — the `firefly new` global scaffolding tool, zero runtime dependencies |

---

## Reference

| Document | Description |
|----------|-------------|
| [Modules](modules.md) | The grouped index of all 32 module guides, and how a package wires itself |
| [CLI Reference](cli.md) | Every `firefly:*` command and `make:firefly-*` generator, including the four contributed by capability packages |
| [Laravel Comparison](laravel-comparison.md) | Side-by-side concept mapping for developers coming from plain Laravel |
| [Versioning](versioning.md) | CalVer (`YY.MM.Patch`), no `version` field, how Packagist derives releases from tags |
| [Contributing](contributing.md) | Monorepo layout, local setup, the gate, the documentation guard, conventions |
| [Publishing](publishing.md) | The release/split runbook — one CalVer tag, one mirror per shippable unit |

---

## Quick Links

- **New to LaraFly?** Start with [Installation](installation.md), then [Getting Started](getting-started.md).
- **Coming from plain Laravel?** Read the [Laravel Comparison](laravel-comparison.md).
- **Want to see it running end-to-end?** Explore the [Lumen Sample](../samples/lumen/) — a full digital-wallet
  vertical slice.
- **Building a web service?** See the [Web Layer Guide](modules/web.md) and [Web Filters](modules/web-filters.md).
- **Understanding the data layer?** Start with [Data & Repositories](modules/data.md), then
  [Relational Data](modules/data-relational.md) and [Transactions](modules/transactional.md).
- **Need messaging or events?** See [Event-Driven Architecture](modules/eda.md), [EDA Brokers](modules/eda-brokers.md),
  and [Messaging](modules/messaging.md).
- **Writing commands/queries?** See [CQRS](modules/cqrs.md).
- **Securing an app?** See [Security](modules/security.md), then [OAuth2 Client](modules/security-oauth2-client.md)
  to sign users in with a provider and [OAuth2 Authorization Server](modules/security-oauth2-server.md) to be
  one yourself.
- **Shipping to production?** See [Actuator](modules/actuator.md), [Observability](modules/observability.md),
  [Tracing](modules/tracing.md), [Logging](modules/logging.md) and [Resilience](modules/resilience.md) — then
  [Admin Dashboard](modules/admin.md) for the browser view over all of it, and read its
  [access model](modules/admin.md#access-the-whole-security-boundary) before enabling it outside `app.debug`.
- **Publishing an API?** See [OpenAPI](modules/openapi.md) — the spec is generated from the same manifests the
  dispatcher and validator use, so it cannot drift.
- **Writing tests?** See [Testing](modules/testing.md) and [Integration Testing](modules/integration-testing.md).
- **Releasing a version?** See [Versioning](versioning.md) and [Publishing](publishing.md), and check the
  [`CHANGELOG.md`](../CHANGELOG.md) at the repo root.
- **Want to contribute?** See [Contributing](contributing.md).

---

*The guided, book-style [*LaraFly by Example*](../book/README.md) — bilingual (English + Spanish), rendered
to PDF + EPUB, its chapter list held in `book/book.yaml` and `book/book.es.yaml` — is available now, alongside
the step-by-step [Tutorial](tutorial.md).*

Apache-2.0 © Firefly Software Solutions Inc.

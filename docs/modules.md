# Modules

LaraFly is one monorepo of **29 installable Composer packages** under `packages/*` — twenty-eight libraries
plus the `firefly/firefly` runtime metapackage — each with its own test suite, and the **32 guides** below
are the long form of what they do. Several packages carry more than one guide, because the surfaces they
ship are read separately: `firefly/data` alone answers for *Data & Repositories*, *Relational Data* and
*Transactions*. Installing a package is the whole wiring step — its `#[Configuration]` class registers the
defaults behind `#[ConditionalOnMissingBean]`, so a bean you declare yourself always wins, and nothing here
asks you to register a provider by hand.

<div class="lf-cards" markdown>

<div class="lf-card" markdown>
<span class="lf-card-title">Foundation</span>
[Error Handling](modules/error-handling.md) · [Dependency Injection](modules/dependency-injection.md) ·
[Configuration](modules/configuration.md) · [Application Context](modules/context.md) ·
[Auto-Configuration](modules/starters.md) · [Validation](modules/validation.md)
<span class="lf-card-note">Stereotypes and the compiled component scan, profiles and `#[ConfigProperties]` binding, the phased boot, `#[Configuration]`/`#[Bean]` starters with conditions, constraint attributes behind `#[Valid]`, and the RFC-7807 error model everything else throws into.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Web &amp; API</span>
[Web Layer](modules/web.md) · [Web Filters](modules/web-filters.md) · [OpenAPI](modules/openapi.md)
<span class="lf-card-note">`#[RestController]` routing off a compiled `RouteManifest`, the ordered `WebFilter` chain on Laravel's middleware stack, and an OpenAPI 3.1 document generated from those same manifests.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Resilience &amp; Scheduling</span>
[Resilience](modules/resilience.md) · [Scheduling](modules/scheduling.md)
<span class="lf-card-note">Retry, circuit breaker, bulkhead, time limiter, rate limiter and fallback, each a `call(callable)` object built from `firefly.resilience.*`; `#[Scheduled]` methods held by a cache or Postgres-advisory distributed lock.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Data &amp; Domain</span>
[Domain (DDD)](modules/domain.md) · [Data &amp; Repositories](modules/data.md) ·
[Relational Data](modules/data-relational.md) · [Transactions](modules/transactional.md)
<span class="lf-card-note">Spring-Data-shaped repositories — derived queries, query by example, `#[Modifying]`/`#[Projection]`/`#[Lock]`/`#[EntityGraph]`, `Page`/`Slice`, the `DataAccessException` family — over Eloquent, with `#[Transactional]` demarcating the boundary.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Eventing &amp; Messaging</span>
[EDA](modules/eda.md) · [EDA Brokers](modules/eda-brokers.md) · [Messaging](modules/messaging.md)
<span class="lf-card-note">One `EventPublisher` port with in-memory, queue, RabbitMQ, Postgres and Kafka adapters, a genuine same-transaction outbox, and the lower-level raw-bytes broker layer beneath it.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">CQRS</span>
[Command/Query](modules/cqrs.md)
<span class="lf-card-note">A `CommandBus`/`QueryBus` mediator with correlation, validation, authorization, caching and metrics in the pipeline, and a bridge that republishes committed domain events onto the event bus.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Security</span>
[Security](modules/security.md) · [OAuth2 Client](modules/security-oauth2-client.md) ·
[OAuth2 Authorization Server](modules/security-oauth2-server.md)
<span class="lf-card-note">A Spring-Security-6-shaped principal model: session-persisted context, form login, HTTP Basic, JWT, deny-by-default `HttpSecurity` URL rules, method security on any stereotyped bean, and both halves of OAuth2 — signing in with a provider, and being one.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Operations</span>
[Actuator](modules/actuator.md) · [Observability](modules/observability.md) ·
[Tracing](modules/tracing.md) · [Logging](modules/logging.md) · [Admin Dashboard](modules/admin.md) ·
[Bean Graph](modules/bean-graph.md) · [Data Browser](modules/data-browser.md)
<span class="lf-card-note">Health, info and metrics over an actuator surface, OpenTelemetry-shaped tracing with W3C propagation, structured JSON/ECS/Logstash logging, and a server-rendered dashboard with a drawn bean graph and a database browser.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Testing</span>
[Testing](modules/testing.md) · [Integration Testing](modules/integration-testing.md)
<span class="lf-card-note">A boot harness, recording doubles for every port, web and data test slices, and a real-Chromium browser suite over the skeleton.</span>
</div>

<div class="lf-card" markdown>
<span class="lf-card-title">Tooling</span>
[Installer](modules/installer.md)
<span class="lf-card-note">The global `firefly new` installer and the `firefly/skeleton` create-project template.</span>
</div>

</div>

Every configuration key the framework reads is written out once, with its default and the reason it has
that default, in the skeleton's `config/firefly.php`. That file is the reference, and it is held to being
one: a key the framework reads that the reference does not document is a failing test.

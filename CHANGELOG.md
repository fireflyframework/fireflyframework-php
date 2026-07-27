# Changelog

All notable changes to LaraFly are documented here. This project uses CalVer (`YY.MM.Patch`).

## [26.07.15] - 2026-07-27
### Fixed
- **`firefly/context`** — boot-order robustness: a real app (created via `composer create-project
  firefly/skeleton`) now boots WITHOUT an app-side `beforeBootstrapping` kernel-binding hook.
  `Firefly\Context\Boot\FireflyServiceProvider` now buffers each provider's contributed `BootPass`
  instances (new `PendingBootPasses`) at `register()` time and drains them into the kernel lazily,
  from the `booting()`/`booted()` callbacks, once the kernel is actually bound — so Laravel's
  alphabetical package-provider discovery order no longer breaks boot. The skeleton's now-unneeded
  `beforeBootstrapping` hook is removed.
- **`firefly/cli`** — the offline create-project test now cleans up its temp directory via
  try/finally, so a failed assertion no longer leaks it.

## [26.07.14] - 2026-07-27
### Added
- `firefly/cli` — the developer-experience console (new Deptrac `Cli` layer, depends-all/depended-by-none): `firefly:cache` (compile all app manifests via every settled package's scanner→compiler + emit `#[Transactional]` proxy classes into `bootstrap/cache/firefly/` for a zero-reflection boot; a `FireflyCacheServiceProvider` binds the compiled wiring manifests + registers the proxy autoloader at boot), `firefly:clear`, `firefly:about` + actuator-over-CLI `firefly:routes`/`firefly:health`/`firefly:metrics`, the `make:firefly-*` generator family (controller/service/component/handler/listener/entity/repository/config-properties), and thin `firefly:serve`/`firefly:db` passthroughs.
- `firefly/firefly` — a `type: metapackage` runtime aggregator (the Composer BOM analog): `composer require firefly/firefly` pulls the whole runtime family.
- `firefly/skeleton` — a `type: project` Laravel-13 create-project template pre-wired with the Firefly family + a sample `#[RestController]`/`#[Service]` + `firefly:cache` in `post-create-project-cmd`; `composer create-project firefly/skeleton my-app` yields a booting, cached app.
- Docs: `docs/modules/getting-started.md` + `docs/modules/cli.md`; a full foundation-flow e2e capstone (+ a `@group integration` Postgres pass via testcontainers).

## [26.07.13] - 2026-07-27
### Added
- `firefly/testing` — the first-party test-support kit: `FireflyTestCase` + `bootFireflyApp()`/`fireflyApplication()` boot harness, `FireflyDatabaseTestCase`/`UsesSqliteMemory`, web/data slice builders + `#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` attribute analogs, recording doubles for Firefly's ports (`RecordingEventPublisher`, `RecordingApplicationEventPublisher`, `RecordingCommandBus`, `StubQueryBus`, `RecordingCqrsMetrics`, `RecordingCommandEventPublisher`, `RecordingMessageBroker`, `RecordingDistributedLock`, `FakeHealthIndicator`, `RecordingTracer`), Firefly Pest expectations (`toHavePublished`/`toHaveHandledCommand`/`toBeUp`/`toHaveRecordedMetric`/`toBeProblemDetails`) + procedural assertions, a fixture layer (`FixtureRegistry`/`AggregateSeeder`/`ListenerSpy`), and a testcontainers hook (`RequiresDocker`/`fireflyConfigFor()`). New Deptrac `Testing` layer (depends on all, depended on by none).

### Changed
- Dogfood: every package's hand-rolled test base + bare-boot test now runs on the `firefly/testing` harness; duplicated doubles (`FakeEventPublisher`, `SpyEventPublisher`, cqrs `RecordingCommandEventPublisher`, eda/messaging `Spy`) deleted in favor of the shipped kit.

## [26.07.12] - 2026-07-26
### Added
- **`firefly/actuator`** — the Spring-Boot-Actuator analogue: a `HealthIndicator` SPI (`Status` UP/DOWN/OUT_OF_SERVICE/
  UNKNOWN, a most-severe `StatusAggregator`, liveness/readiness probe groups, `/health/{group}`, 503-on-DOWN,
  `show-details`) with built-in `Ping`/`DiskSpace`/`Db` indicators discovered by a bean-scan `HealthContributorRegistrar`;
  an `ActuatorEndpoint` contract + `ActuatorRegistry` + a HAL `/actuator` index; a route-registration BootPass mounting
  framework endpoints on the illuminate `Router` under `/actuator`; `/info` (`InfoContributor` port + `App`/`BuildInfo`),
  `/env` (masked), `/beans`, `/conditions`, `/mappings`, `/loggers` (GET/POST), `/scheduledtasks`. Exposure model
  (secure-default `health,info`; unexposed → 404); fail-safe errors via `ProblemDetailsRenderer`. Secured entirely by
  M11 config (recommended `firefly.security.http.rules` lockdown) with zero code edge to `firefly/security`. New Deptrac
  `Actuator` layer (top-of-stack).
- **`firefly/observability`** — the Micrometer/Prometheus analogue: a first-party pure-PHP `MeterRegistry`
  (`SimpleMeterRegistry`, counter/gauge/timer, idempotent tag sets) + a `MetricsRecorder` port (+ NoOp); a pure-PHP
  Prometheus 0.0.4 text exposition (no ext, no OTel) behind `/actuator/prometheus` and a Micrometer-JSON
  `/actuator/metrics`, both implementing the actuator contract and gated on a `MeterRegistry` bean; an HTTP `MetricsFilter`
  auto-instrumenting `http_server_requests_seconds`; the real `MeterRegistryCqrsMetrics` (wins the M10 seam via
  `#[Order(500)]` + `#[ConditionalOnMissingBean]`); a resilience circuit-breaker state gauge + process metrics; a
  correlation-id log processor; and a `Tracer` port (`NoOpTracer`; OpenTelemetry deferred to SP-7). New Deptrac
  `Observability` layer (top-of-stack, gated on a `MeterRegistry` bean).

## [26.07.11] - 2026-07-24
### Added
- **`firefly/security`** — the first-party security core: an immutable `Authentication`/`SecurityContext`/`GrantedAuthority`
  principal model in a `Context`-backed `SecurityContextHolder` (cleared per request); authentication ports with
  `InMemoryUserDetailsService`, constant-time `PasswordEncoder`s (bcrypt/argon2id/delegating), a `ProviderManager`/
  `DaoAuthenticationProvider`, a `JwtService` (mandatory `exp`, weak-secret boot refusal), a local-JWT Bearer filter,
  and an OAuth2 resource-server JWKS filter (`JwksProvider` port, no live network in tests, `iss`/`aud`
  confused-deputy validation); deny-by-default URL authorization (`HttpSecurity` DSL + `HttpSecurityFilter`, 401/403
  via kernel exceptions); method security (`#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]`) compiled by a single
  `MethodSecurityScanner` into a `var_export` manifest and enforced at the CQRS bus (real `Command`/`QueryAuthorizer`),
  the controller dispatcher (a new no-op `ControllerSecurityGuard` port in `firefly/web` + a real impl), and
  imperatively (`AuthorizationChecker`) — all via a hand-rolled **no-`eval`** whitelist expression evaluator,
  `RoleHierarchy`, and a deny-all `PermissionEvaluator`; the real `AuditorAware` for the M8 auditing seam; and CSRF
  (double-submit) + security-headers hardening filters. A boot-time mutual-exclusivity guard refuses local-JWT +
  OAuth2 resource-server enabled together. Opt-in, secure-by-default, fail-closed, zero boot reflection. New Deptrac
  `Security` layer (top-of-stack).
### Changed
- **`firefly/web`** — added the `ControllerSecurityGuard` enforcement port (no-op `AllowAllControllerSecurityGuard`
  default) invoked by `ControllerDispatcher` after argument resolution; `firefly/security` binds the real guard.
### Fixed
- **`firefly/security`** — `MethodSecurityScanner` now rejects, at scan (cache) time, any `#[Secured]`/`#[RolesAllowed]`
  role/authority value containing a single quote, closing an expression-injection gap where a crafted value could
  compile into grammar-valid text (e.g. splicing in `or permitAll()`) and silently widen access; mirrors the
  `HttpSecurity::assertSafeValue()` guard already shipped for the URL-rule DSL/config path.

## [26.07.10] - 2026-07-24
### Added
- **`firefly/cqrs`** — the CQRS dispatch layer: a synchronous in-process `CommandBus` (`send`) / `QueryBus` (`ask`)
  mediator with `#[CommandHandler]`/`#[QueryHandler]` class stereotypes (message type inferred from the `handle()`
  parameter) discovered by a single `HandlerScanner` into a `HandlerManifest` and populated into a `HandlerRegistry`
  by a `CqrsHandlerWiringPass`; a bounded pipeline of injectable, no-op-by-default seams (validation over the shipped
  `Validator` via `Validatable`, authorization, correlation, metrics, query cache); category-preserving
  `CommandProcessingException`/`QueryProcessingException`; and the domain→integration-event bridge — a guarded
  wildcard listener re-emitting every committed `DomainEvent` onto the `firefly/eda` `EventPublisher`
  (`CommandEventPublisher` port + `NoOpEventPublisher`/`EdaCommandEventPublisher`, `#[PublishDomainEvent]` routing,
  LOG/RAISE failure strategy). `#[Transactional]` handlers get transaction interception for free from firefly/data.
  Always-on auto-configuration. No `Cqrs → Data` Deptrac edge.

## [26.07.9] - 2026-07-23
### Added
- **`firefly/eda`** — the async event-transport bus: an `EventPublisher` broker-bus port with an in-memory default
  adapter and a Laravel-queue async adapter (`QueueEventBus` enqueues a `DispatchEventJob` carrying the
  `EventEnvelope`; the worker reconstructs the subscriber registry from the compiled manifest and match+invokes), a
  `#[EventListener]` pattern attribute → `EventListenerScanner` → `EventListenerManifest` → `EventListenerWiringPass`,
  a `Serializer` seam (`JsonSerializer`), and a linear-backoff retry / `DeadLetterStore` helper
  (`x-original-topic`/`x-exception`). Always-on auto-configuration.
- **`firefly/messaging`** — the raw-bytes broker layer: a `MessageBrokerPort` (`Message` = topic + bytes + key +
  headers) with in-memory (broadcast + consumer-group round-robin) and Laravel-queue adapters, a broker-agnostic
  `#[MessageListener]` (topic/group/retries/retryDelay/deadLetterTopic) → `MessageListenerScanner` →
  `MessageListenerManifest` → `MessageListenerWiringPass`, and a bytes-aware per-listener retry/DLQ helper.
  Always-on auto-configuration. Independent sibling of `firefly/eda` (no dependency between them).

## [26.07.8] - 2026-07-22
### Added
- **`firefly/domain`** — the DDD base: `Entity` (identity equality), `ValueObject` + `ValueObjectEquality`,
  `AggregateRoot` (pending domain-event buffer: `raiseEvent`/`pendingEvents`/`pullEvents`/`clearEvents`), and a
  reflection-free `DomainEvent` (uuid `eventId` / `occurredAt` / `eventType`).
- **`firefly/data`** — repositories (`CrudRepository`/`PagingAndSortingRepository` ports + `EloquentRepository`),
  extended-Spring derived queries (`DerivedQueryParser` + `__call` dispatch) with a `#[Query]` escape hatch,
  `Specification` composition + `Page`/`Pageable`/`Sort` pagination, and soft-delete/auditing/optimistic-locking
  support. The declarative `#[Transactional]` interception core — `TransactionalScanner`→`TransactionalManifest`,
  a reflection-free `ProxyClassGenerator` + state-preserving `ProxyFactory`, `TransactionInterceptor`/
  `TransactionTemplate` over MANUAL `DB` begin/commit/rollBack (all 7 propagation modes, `rollbackFor`/
  `noRollbackFor`), and a `TransactionalBeanPostProcessor` wired via the M4 seam at phase 700. Auto after-commit
  domain-event dispatch (`AggregateTracker` + `DomainEventDispatcher` via `DB::afterCommit`). Always-on auto-configuration.

## [26.07.7] - 2026-07-17
### Added
- **`firefly/resilience`** — programmatic, cache-backed resilience: `ResilienceRegistry` (config-driven named
  instances) + Retry, CircuitBreaker, RateLimiter, Bulkhead, TimeLimiter, Fallback; `Duration` parser;
  `BulkheadFullException`; always-on auto-configuration.
- **`firefly/scheduling`** — `DistributedLock` port with `NoneLock`/`CacheLock`; `#[Scheduled]` attribute +
  `ScheduledScanner`/`ScheduledManifest`; `ScheduleWiringPass` (deferred, lock-guarded registration onto Laravel's
  scheduler); config-selected lock backend.
- **`firefly/scheduling-postgres`** — `PgAdvisoryLock` (session-scoped Postgres advisory lock), `#[ConditionalOnProperty]`-gated.

## [26.07.6] - 2026-07-17
### Added
- **`firefly/web`** — the HTTP layer: `#[RestController]` (a `#[Component]` stereotype, so controllers get
  constructor DI for free) with `#[RequestMapping]` + `#[GetMapping]`/`#[PostMapping]`/… compiled by a single
  `RouteScanner` into a `RouteManifest`; parameter binding (`#[PathVariable]`/`#[QueryParam]`/`#[RequestBody]`/
  `#[RequestHeader]`/`#[UploadedFile]`) with `#[Valid]` interception; JSON-native content negotiation over a
  `MessageConverter` seam (q-value `Accept` parsing; XML deferred to the seam); RFC-7807 rendering of every
  `FireflyException` as `application/problem+json` with `#[ExceptionHandler]` (local) + `#[ControllerAdvice]`
  (global) resolution; and an `#[Order]`-driven `WebFilter`→Laravel-middleware chain with framework
  `RequestContextFilter`/`CorrelationIdFilter`. A `RouteWiringPass` (phase `WiringPasses`) registers native
  Laravel routes, so dispatch runs inside the HTTP-kernel middleware pipeline (CORS/CSRF/secure-headers reuse).
- **`firefly/validation`** (extended) — the Bean-Validation constraint layer `#[Valid]` triggers: per-property
  constraint attributes (`#[NotBlank]`/`#[Size]`/`#[Email]`/`#[Iban]`/… + the `#[Rules]` escape hatch) mapped
  to Illuminate rules by a single `ConstraintScanner` (nested-cascade + cycle guard), a compiled
  `ConstraintManifest`, and a `BeanValidator` that reuses the M5 `IlluminateValidator`. `#[Valid]`'s target is
  broadened to also allow properties.

### Changed
- `Firefly\Kernel\Version::VERSION` → `26.07.6` (the single frozen-package edit).

## [26.07.5] - 2026-07-16
### Added
- **`firefly/autoconfigure`** — the auto-configuration engine:
  - `AutoConfiguration` — a discovered provider base that records only its candidacy into a container-bound
    `AutoConfigurationCollector` at `register()` time (never touching the kernel), so provider-registration
    order cannot affect wiring.
  - `DefinitionAssembler` — joins M2 `ComponentManifest` + M4 `ContextManifest` into conditioned
    `BeanDefinition`s (closing M4's deferred scanner→definition seam), and `AutoConfigManifestCompiler`, the
    compile façade over the M2/M4 scanners.
  - `AutoConfigDiscoveryPass` (phase 200, discover + assemble, no registry write) and `AutoConfigurationsPass`
    (phase 500, drain into the registry as `DefinitionSource::AutoConfiguration`), consumed by M4's incremental
    `ConditionPassTwoPass` for `#[ConditionalOnMissingBean]` back-off and `(order, FQCN)` first-wins.
  - `FireflyAutoConfigureServiceProvider` — the auto-discovered bootstrap that binds `FireflyKernel` +
    `BootContext` and contributes the full boot pipeline, scanning the app by convention from
    `firefly.scan.paths`. No modification to the frozen `firefly/context`.
- **`firefly/validation`** — a `Validator` port with a `validate(data, rules)` primitive over
  `Illuminate\Validation`, an inert `#[Valid]` marker (interception lands in M6), ~16 financial-domain `Rule`
  objects (IBAN, BIC, Luhn, ISIN, CUSIP, ...), and a `ValidationAutoConfiguration` that installs the default
  adapter and backs off when the app binds its own `Validator` — the first real end-to-end consumer of the
  auto-configuration engine. Failures throw the kernel's `ValidationException` (HTTP 422) carrying `FieldError`s.

## [26.07.4] - 2026-07-15
### Added
- **`firefly/context`** — the boot engine:
  - `FireflyKernel` — an ordered boot pipeline (`BootPhase`/`BootPass`) that later packages extend
    exclusively via `addPass()`, sorted by a deterministic `(phase, order(), FQCN)` total order —
    never by service-provider registration order — with a `FireflyServiceProvider` base class every
    package contributes passes through.
  - Two-pass conditional registration — `#[ConditionalOnProperty]`/`#[ConditionalOnClass]`/
    `#[ConditionalOnMissingClass]`/`#[ConditionalOnProfile]` (registry-independent) and
    `#[ConditionalOnBean]`/`#[ConditionalOnMissingBean]` (evaluated incrementally against the
    `BeanDefinitionRegistry`, never against resolved instances) — with bean conditions rejected with a
    `ConfigurationException` on a user component, since only auto-configurations run late enough for
    "does this bean exist?" to have a deterministic answer.
  - `BeanPostProcessor` — a two-pass `beforeInitialization`/`afterInitialization` chain, `#[Order]`-sorted
    and frozen from the compiled manifest, with `#[PostConstruct]` invoked strictly between the two
    passes and proxy substitution confined to `afterInitialization()`.
  - `#[PostConstruct]`/`#[PreDestroy]` lifecycle callbacks, dependency-injected via `$container->call()`,
    destroyed in reverse order at `ApplicationContext::close()`.
  - `ApplicationEventPublisher` over Laravel's event dispatcher, `#[AsEventListener]`, and the
    `ContextRefreshedEvent`/`ApplicationReadyEvent`/`ContextClosedEvent` lifecycle events.
  - Octane state hygiene: `OctaneListener`/`StateResetter` drain scoped beans' `#[PreDestroy]`
    callbacks and reset scoped instances on the request sandbox, per request/task/tick, while the boot
    pipeline itself runs once per worker.
- Harness: a `Context` Deptrac layer (may depend on `Kernel` + `Container` + `Config`).

### Fixed
- **`firefly/container`** — `#[Lazy]` was silently ignored. The attribute was scanned-but-discarded:
  `ComponentScanner` never read it and `ComponentDescriptor` had no field for it, so marking a
  component or `#[Bean]` method `#[Lazy]` had no effect whatsoever. It is now captured through the
  compiled manifest and honored by the boot engine's eager-singleton phase. (Cached manifests written
  by earlier versions still load; the field defaults to `false`.)

## [26.07.3] - 2026-07-15
### Fixed
- **`firefly/container`** — an explicit `#[Bean]` factory whose return type is an interface is no longer silently
  clobbered by interface auto-binding. `ContainerRegistrar::wireInterfaces()` now skips the single-default binding for
  any interface already bound by a `#[Bean]`, so an explicit bean definition takes precedence over an auto-wired
  `#[Primary]`/sole implementation (matching Spring semantics). Implementations are still tagged for `getAll()`.

## [26.07.2] - 2026-07-15
### Added
- **`firefly/config`** — Spring-style configuration over Laravel's config repository:
  - **Profiles** — `Profiles`/`ProfileResolver` (active profiles from `FIREFLY_PROFILES_ACTIVE`, else `APP_ENV`,
    else `default`) and a `#[Profile]` marker.
  - **Typed `Config` accessor** — `string()`/`int()`/`bool()`/`array()`/`get()`/`has()` with fail-fast
    `ConfigurationException` on missing-required or type-mismatched keys.
  - **`#[ConfigProperties]` binding** — a first-party `ReflectionConfigBinder` (behind a `ConfigBinder` seam)
    maps a config subtree onto a plain readonly DTO, with recursive nested binding, discovered by
    `ConfigPropertiesScanner` and compiled to a cached, Octane-safe manifest.
  - **`ConfigValueResolver`** — a config→env→default `ValueResolver` bound over `firefly/container`'s default,
    closing the resolver seam so `#[Value]` reads application config.
- Harness: a `Config` Deptrac layer (may depend on `Kernel` + `Container`).

## [26.07.1] - 2026-07-14
### Added
- **`firefly/container`** — attribute-driven dependency injection over `Illuminate\Container`:
  - Stereotype attributes `#[Service]`/`#[Repository]`/`#[Configuration]` (specialising `#[Component]`) and
    modifiers `#[Bean]`/`#[Primary]`/`#[Order]`/`#[Lazy]`/`#[Qualifier]`.
  - `ComponentScanner` + `ManifestCompiler` — PSR-4 scanning compiled to a cached, Octane-safe manifest.
  - `ContainerRegistrar` — Singleton/Transient/Scoped scopes, interface auto-binding, `#[Primary]` defaults,
    named aliases, tagged ordered lists, and `#[Bean]` factories with method injection.
  - `Firefly\Container\Container` facade — resolve by type/interface/name and `#[Order]`-sorted `getAll()`.
  - `#[Value]` injection (`${ENV:default}` + `#{expr}`) via a pluggable `ValueResolver`.
- Harness: Larastan added to PHPStan; a `Container` Deptrac layer (may depend on `Kernel` only).

## [26.07.0] - 2026-07-14

First milestone of the LaraFly Foundation cycle: the Composer monorepo, the quality harness, and the
`firefly/kernel` package.

### Added
- **Monorepo** of Composer packages (`symplify/monorepo-builder`) with local path-repo dev wiring under `packages/*`.
- **Quality harness** behind one `composer check`: Pest, PHPStan (level max), Laravel Pint, Deptrac
  (`deptrac/deptrac` 4.x), plus CalVer (`YY.MM.Patch`) versioning with a tag↔constant consistency test.
- **CI** (GitHub Actions): PHP 8.3/8.4/8.5 quality matrix + a strict MkDocs docs build.
- **Docs** (MkDocs Material): home, architecture overview, and the Error Handling module page.
- **`firefly/kernel`** — the zero-dependency foundation package:
  - `Lifecycle` — the `start()`/`stop()` contract for infrastructure adapters.
  - `FireflyException` taxonomy — a base plus 22 typed exceptions across Business, Security, Infrastructure,
    External, and Framework/Plugin groups, each carrying a stable error code, HTTP status, category, and severity.
  - RFC-7807 error model — `ErrorResponse` (with `fromException()`), `FieldError`, `ErrorCategory`, `ErrorSeverity`.
  - `Version` — the CalVer framework version constant.

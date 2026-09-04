# Changelog

All notable changes to LaraFly are documented here. This project uses CalVer (`YY.MM.Patch`).

## [Unreleased]

Cut as `26.09.1` when released: `Firefly\Kernel\Version::VERSION`, this heading, and the README version
badge move together (see [Versioning](docs/versioning.md)), and `tests/VersionConsistencyTest.php` fails the
build if any one of the three drifts.

A correctness release that also grew two surfaces. Several headline features were found not to work at all
outside the compiled boot, and two of the failures were **fail-open** in the security sense — the application
kept serving, unguarded, with nothing logged; every fix below was reproduced by a failing test first. Alongside
them, LaraFly gained the two things a framework this shape is expected to have and did not: a browser dashboard
over the actuator (`firefly/admin`) and an OpenAPI 3.1 document generated from the manifests it already holds
(`firefly/openapi`). Both are opt-in Composer packages, outside the `firefly/firefly` metapackage, and neither
needs npm or a CDN.

### BREAKING

- **`packages/container` — two non-`#[Primary]` `#[Bean]` methods returning the same type now THROW at
  registration.** They previously booted, and one of the two beans silently did not exist: a bean name was
  only ever recorded as `alias($returns, $name)`, and an alias is a pointer to a key rather than a binding of
  its own, so both names pointed at the single type key, that key held whichever factory registered last, and
  `getByName('memoryCache')` and `getByName('redisCache')` handed back the identical object. `#[Primary]` could
  not break the tie because `BeanDescriptor::$primary` was read nowhere in the bean path. **Migration:** give
  each competing `#[Bean]` method a distinct name and mark exactly one `#[Primary]` — the type key then
  aliases the primary and every candidate stays individually resolvable. Rejected at registration (where the
  stack trace still points at the manifest): competing beans that are anonymous, that share a name, that are
  named after the contested type itself, or that declare more than one `#[Primary]`. A contested type with no
  `#[Primary]` stays *bound* — to a guard that throws naming every candidate — so `#[ConditionalOnMissingBean]`
  still sees that a bean of that type exists. See [Dependency Injection](docs/modules/dependency-injection.md).

### Added
- **`firefly/openapi` — an OpenAPI 3.1 document that cannot drift from the server.** Generated from the
  artifacts the framework already holds in memory: `RouteManifest` for paths, verbs, declared statuses, route
  names and the per-parameter binding plan; `ConstraintManifest` for request-body schemas and their `required`
  lists; `firefly/kernel`'s `ErrorResponse` for the RFC 9457 problem component. There is no annotation dialect
  and no second description of the API, so there is nothing to keep in sync. `#[NotBlank]`, `#[Size]`,
  `#[Min]`/`#[Max]`, `#[Email]`, `#[Pattern]`, `#[Percentage]`, `#[Money]` and the rest become JSON Schema
  keywords; anything JSON Schema cannot state (`after:now`, a Luhn checksum, a PCRE flag ECMA-262 has no syntax
  for) is recorded under the `x-firefly-constraints` specification extension rather than dropped silently.
  Nested `#[Valid]` DTOs get their own component, so a self-referential DTO terminates as a `$ref` cycle. Paths,
  verbs and components are sorted, so a regenerated document diffs cleanly and stays worth committing.
  `php artisan firefly:openapi` writes it to `--output=` (with a summary line) or **raw** to stdout via
  Symfony's `OUTPUT_RAW`, so `firefly:openapi | <client-generator>` gets exactly the document's bytes. Three
  routes — spec, console, console assets — are mounted natively from a `BootPass` at configurable paths, which
  an attribute route could not be, and which also keeps the package from documenting itself. See
  [OpenAPI](docs/modules/openapi.md).
- **`firefly.openapi.viewer.style` — `swagger` (default) | `builtin` | `cdn`.** The default console is the
  **official Swagger UI, served from the application's own origin** out of the `swagger-api/swagger-ui` composer
  package (a hard dependency, so the files are already on disk): byte-for-byte the distribution Swagger
  publishes — full feature set, deep linking, try-it-out, OAuth2 — with **no CDN request and no npm step**, so
  it still renders in the air-gapped and strict-CSP deployments where an internal API console is most wanted.
  Asset serving is a whitelist of seven basenames, each `realpath()`-checked inside the dist directory, behind a
  route whose `{file}` segment cannot express a traversal; the files are immutable for a pinned version and are
  sent with a one-year `immutable` cache header and an auto ETag. `builtin` is the hand-written, dependency-free
  reference (no third-party JavaScript at all) and is also the automatic fallback when the dist is absent, so a
  missing package never renders a console whose assets 404. `cdn` fetches Swagger UI from `cdn.jsdelivr.net` and
  is the only style that makes a third-party request at page view. The older boolean `firefly.openapi.viewer.cdn`
  (default `false`) still forces the CDN page and wins over `style`, so an application that set it keeps the
  behaviour it configured.
- **`firefly/admin` — a browser dashboard over the actuator**, the Spring Boot Admin analogue, mounted at
  `firefly.admin.base-path` (default `/firefly`). Thirteen pages in three operator-shaped groups: overview,
  health, metrics and HTTP traffic; beans, **bean graph**, conditions, routes and scheduled tasks; environment,
  config properties, caches and loggers. It reads each `ActuatorEndpoint` **in-process** from `ActuatorRegistry`,
  deliberately bypassing `ExposureModel` — so it renders pages the JSON surface keeps unexposed while that
  surface stays secure-by-default — and honours the per-endpoint kill switch
  (`firefly.management.endpoint.{id}.enabled`), because that key means "off", not "unpublished". A page whose
  endpoint is unregistered or switched off is hidden from the menu rather than linked; a throwing endpoint
  degrades its own panel; health details are read from `HealthContributorRegistry` directly rather than through
  the endpoint's `show-details` disclosure policy. Plain Blade with inline CSS — no npm step, no CDN — and it
  mounts nothing at all when no view factory is bound. See [Admin Dashboard](docs/modules/admin.md).
  - **SECURITY — `firefly.admin.enabled` defaults to the value of `app.debug`.** Because the dashboard bypasses
    exposure, its own URL is the entire boundary in front of `beans`, `env` and `conditions`. An app already
    serving stack traces is a development environment by definition; an app with debug off must opt in
    explicitly, and an explicit value wins in both directions. The dashboard ships **no authentication of its
    own** and has no code edge to `firefly/security`: an application that enables it outside debug **must put
    the route behind its own auth middleware** (`firefly.security.http.rules` covers `firefly` and `firefly/*`
    with no code change).
- **The bean graph (`/firefly/graph`)** — a drawn, layered dependency diagram, not another table.
  `ComponentScanner` now records each component's constructor class/interface types at **scan** time
  (`ComponentDescriptor::$dependencies`, declared last with a default so an older compiled manifest still
  rehydrates), and `BeansCatalog` publishes them, so answering "what depends on what" costs no request-time
  reflection. `BeanGraph` resolves every dependency through an interface index first — a constructor asks for
  `EventPublisher`, the bean that satisfies it is `PostgresEventPublisher` — and marks the edge `via` so the
  indirection is visible rather than silently substituted; layering is a longest-path assignment so arrows read
  downward; a cycle terminates the walk and is **reported** rather than hanging the page, which turns "the app
  died at boot with no message" into a named pair of classes. Past 220 nodes the diagram is suppressed in favour
  of the filterable relations table, and constructor types satisfied by a Laravel binding rather than a bean are
  listed as "provided outside the container" rather than dropped. See [Bean Graph](docs/modules/bean-graph.md).
- **`Firefly\Context\Scan\AppScan`** — the seam every capability package uses to resolve its own manifest:
  compiled artifact, else an in-process scan of `firefly.scan.paths`, else empty. Routes, `#[ControllerAdvice]`
  handlers, CQRS handlers, event/message listeners, scheduled tasks, validation constraints, method-security
  rules, `#[ConfigProperties]` DTOs and the `#[Transactional]` manifest all resolve through it, so an uncached
  application behaves exactly like a cached one. `firefly/cli` joins the `firefly/firefly` metapackage.
- **`firefly.security.method.strict`** (default `false`) — refuses to boot when no compiled method-security
  manifest exists, instead of falling back to the scan. The only defence against a build that ships without
  the compile step.
- **`firefly.observability.metrics.store` / `.ttl`** — names a cache store, swapping `SimpleMeterRegistry` for
  the new `CacheMeterRegistry` so counters and timers survive the request that recorded them. `increment()`
  and `record()` use the store's atomic increment (durations accumulate as integer microseconds, because
  `increment()` is integer-only and a float read-modify-write drops samples); `setGauge()` is last-writer-wins;
  `meters()` rehydrates from one index rather than a key scan. Opt-in: on the `array` driver it would be no
  better than memory.
- **`#[Controller]`** — the HTML stereotype (Spring's `@Controller` to `#[RestController]`'s
  `@RestController`), extending `#[RestController]` so `RouteScanner`'s `IS_INSTANCEOF` filter finds it
  unchanged. `ResponseFactory` now renders `View`/`Renderable`/`Htmlable` and the new `ModelAndView` as
  `text/html`; arrays and scalars still negotiate to JSON. A bare `string` is deliberately **not** a view name.
- **`#[ControllerAdvice]`/`#[ExceptionHandler]` are wired for the first time** — `RouteScanner`'s
  `scanExceptionHandlers()` always existed, but nothing compiled the result, so `ExceptionHandlerRegistry` was
  empty in every real boot while the docs taught it as working. `firefly:cache` now emits
  `exception-handlers.php`, and compiles 13 manifests in total.
- **`packages/config`** — relaxed binding (exact → `snake_case` → `kebab-case` → `SCREAMING_SNAKE_CASE`,
  acronym-aware) and `#[Profile]` gating for `#[ConfigProperties]` DTOs.
- **`packages/resilience`** — `circuit-breaker.minimum-number-of-calls` and `.half-open-probe-timeout`,
  `bulkhead.permit-ttl`, and `firefly.resilience.store.lock-block-timeout` (default `0.5`s) for the mutex wait
  budget.
- **`skeleton/config/firefly.php` is now a full configuration reference** — every `firefly.*` key the framework
  reads, grouped by capability, with its real default and what it does; advanced keys stay commented out at
  their defaults. This release adds the `firefly.openapi.*` block (including `viewer.style` and the legacy
  `viewer.cdn`), the `firefly.observability.httpexchanges.*` block (`enabled`, `capacity`, `store`, `ttl`,
  `include-headers`, `exclude`) and `firefly.management.info.runtime.enabled`, and the file was re-derived
  mechanically against the keys the source actually reads, in both directions. `skeleton/.env.example` carries the ones that usually vary per environment. The skeleton
  also gains a `#[Controller]` welcome page (nothing on it hard-coded — real bean/condition counts, the real
  route table, the real actuator registry) and its first test suite.

### Changed
- **`#[Qualifier]` on a parameter is honoured.** It declared `TARGET_PARAMETER` from day one and nothing read
  it, so `#[Qualifier('redisCache')] Cache $cache` silently received whatever `Cache::class` resolved to. It
  now rides `ContextualAttribute` — the seam `#[Value]` already used — adding no reflection that was not
  already happening and leaving the compiled manifest shape untouched.
- **`#[Bean]` discovery no longer compares stereotype short names.** The gate was `$shortAttr ===
  'configuration'`, the one place in the scanner that abandoned `IS_INSTANCEOF`, so `#[Bean]` methods on a
  user-defined stereotype extending `#[Configuration]` — or on a plain `#[Component]`, Spring's "lite mode" —
  vanished from the manifest while the class itself was still bound.
- **`make:firefly-*` output.** `-handler` writes two files (the handler *and* the concrete command/query class
  its `handle()` takes); `-listener` puts `#[Component]` on the generated class; `-repository` generates a
  concrete `#[Repository]` extending `EloquentRepository` instead of an unresolvable interface.
- **`firefly.management.endpoints.web.exposure.exclude` honours `*`**, matching `include` and Spring — the
  documented kill switch used to expose everything `include` named. An endpoint body renders as `{}` rather
  than `[]` when empty.
- The skeleton drops `app/Support/CachedTransactionalConfiguration.php`, the hand-written workaround every
  application needed while `DataAutoConfiguration` bound an empty `TransactionalManifest`.
- **Docs, book and README cover the two new packages.** New module guides
  [OpenAPI](docs/modules/openapi.md), [Admin Dashboard](docs/modules/admin.md) and
  [Bean Graph](docs/modules/bean-graph.md), wired into `docs/README.md` and `docs/index.md`; the actuator guide
  gains `/actuator/httpexchanges` + `/actuator/process` and a pointer to the dashboard's access model; the CLI
  reference gains a table of commands contributed by other packages (`firefly:openapi`, `firefly:eda:consume`,
  `firefly:outbox:relay`). *LaraFly by Example* is updated in **both** languages: Chapter 11 gains the
  thirteen-page dashboard table and a full bean-graph section (interface resolution, longest-path layering,
  cycle reporting, the 220-node ceiling), and Chapter 4A's "CDN flag" section is replaced by the three viewer
  styles, the whitelisted asset route and the honest cost of `cdn`. Every fenced PHP listing still passes
  `php -l` (219 per language). Package counts corrected from 25/26 to **27 packages / 28 shippable units** in
  the README and the publishing runbook.
- Docs corrected against source throughout: the CLI's cached-vs-uncached boot, the resilience circuit-breaker
  and bulkhead tables and their state prose, configuration's relaxed binding and profile gating, the web
  layer's HTML rendering, security's fail-open note and full config table, observability's cross-process
  registry, and the "compilation lands in M15 — until then bind the manifest yourself" caveat that five module
  guides still carried.
- **Documentation for the rebuilt bean graph, the data browser and the OpenAPI schema pipeline.** A new
  [Data Browser](docs/modules/data-browser.md) guide covers `firefly/admin`'s Django-admin-style view over the
  data layer: what it discovers (every bean whose scan-time interface list contains `CrudRepository`, read from
  the compiled `BeansCatalog` rather than a fresh scan, so it can never offer a resource the container never
  registered), why `firefly.admin.data.enabled` defaults to **`false`** and deliberately does *not* follow
  `app.debug` or `firefly.admin.enabled` (beans and config are facts about the application; these are facts
  about its **users**), why writes need `firefly.admin.data.writable` **on top of that** (visibility and custody
  are different decisions), and why **there is no `create()`** and never will be — an aggregate's invariants live
  in its constructor, and a form built from a column list can only satisfy them by writing columns the domain
  model considers impossible. Also documented: the four listing paths and the honest cost of the unpaged one,
  search bound-never-interpolated, columns derived from the resource rather than from a row, the closed
  five-value display-type vocabulary and why `decimal` maps to `string`, the identifier/secret write refusals
  enforced twice, and why no rendered error text is ever an exception message.
  [Bean Graph](docs/modules/bean-graph.md) is rewritten for the three node kinds — components, `#[Bean]`
  **products** and `#[ConfigProperties]` DTOs — plus the `injects`/`produces` edge distinction, the identity
  rule for a contested `#[Bean]` type, and why cycles are reported rather than fatal; the stale "`#[Bean]`
  factory-method parameters are not drawn" limitation is gone, because they are.
  [OpenAPI](docs/modules/openapi.md) gains a full "How a request DTO becomes a schema" section: the three
  sources and why the compiled manifest beats the `#[Constraint]` attributes, why no `additionalProperties:
  false` is emitted, the complete **attribute → compiled rule → JSON Schema keyword** table mapped from
  `ConstraintSchemaMapper` (correcting `#[Negative]`, which produces `exclusiveMaximum`, not
  `exclusiveMinimum`), first-writer-wins, the 3.1 nullable spelling, the one-`pattern`-slot `allOf` fallback,
  `list<X>` element types read from the constructor docblock via the same `dtos` table `ArgumentResolver`
  hydrates from, and the narrowed `{}`-vs-`[]` rewrite now that a constructor default genuinely does emit an
  empty list. The `firefly.openapi.*` config table also gains the five optional Info Object keys that were
  shipping undocumented — `summary`, `terms-of-service`, `contact.*` and `license.*`, with the rule that
  `license.name` gates the whole object and `license.identifier` wins over `license.url`, since 3.1 makes the
  two mutually exclusive. *LaraFly by Example* is extended in **both** languages: Chapter 11 gains a "three
  kinds of node" section for the graph and a data-browser section placed deliberately beside the access-model
  argument it contradicts. Every fenced PHP listing still passes `php -l` (220 per language).

### Fixed
- **`packages/security` — method security failed OPEN.** Both enforcement sites treat "no rule for this
  method" as ALLOW, so the unconditional empty `SecurityMethodManifest` silently disabled every
  `#[PreAuthorize]`, `#[Secured]` and `#[RolesAllowed]` in the application. Only `firefly/cli` — then a
  `require-dev` package absent from the metapackage — ever bound the compiled rules.
- **`packages/security` — the expression evaluator failed OPEN.** `SecurityExpressionEvaluator` is a singleton
  whose parse state lives on the instance, and `hasPermission()` calls application code (a user-supplied
  `PermissionEvaluator`) that may evaluate an expression of its own on that same singleton. The inner call
  overwrote the outer parse state, so `hasPermission(#id, 'read') and hasRole('ADMIN')` returned **true** for a
  principal holding no authorities at all. State is now saved and restored in a `finally`.
- **Boot — the framework only worked in its compiled state.** `firefly:clear` on a freshly created skeleton
  made the app 404 every route it owned, and no quality gate could see it. Fixed by `AppScan` above.
- **`packages/data` — `#[Transactional]` was a silent no-op.** Nothing ever loaded the compiled
  `transactional.php`, so `hasProxyFor()` was always false. `ProxyMaterializer` now makes proxies loadable on
  both paths (classmap when compiled, generated per-process when not) *before* the manifest is handed out.
- **`packages/eda-postgres` — with `provider=postgres` no `#[EventListener]` was ever subscribed and outbox
  rows were ACKed without being delivered**: silent data loss in the headline feature. `firefly:outbox:relay`
  could not work either, because `downstream_provider` selected no publisher; it now resolves a shipped alias,
  an `EventPublisher` class-string or a bound container id, validates at command time (not boot), refuses a
  `PostgresEventPublisher` downstream, and fails loudly instead of exiting successfully when unconfigured.
- **`packages/eda` — `#[EventListener(order:)]` was discarded at dispatch.** It round-tripped through the
  manifest and the wiring pass then iterated `all()`; it now iterates `ordered()`.
- **`packages/resilience` — the CircuitBreaker wedged permanently in HALF_OPEN** when a probe threw a
  non-recorded exception or its worker died, rejecting 100% of traffic to a healthy dependency until an
  operator flushed the cache. Probe permits are now expiring leases, an ignored exception explicitly returns
  its permit, and bulkhead permits (which leaked the same way, and could be driven negative by an unmatched
  `release()`) are leases too. `state()` reported a stale `open` for a breaker whose wait window had elapsed,
  so the actuator gauge called a recovering breaker hard-down; it now reports the state `admit()` would decide.
  The store's mutex WAIT budget is separated from its HOLD TTL, so a `timeout: 0` rate limiter no longer blocks
  five seconds and then surfaces an unmapped `LockTimeoutException` as a bare HTTP 500 — it raises a 503
  `RESILIENCE_STORE_LOCK_TIMEOUT`.
- **`packages/validation`** — `#[Size]` silently flipped from length to numeric semantics beside any constraint
  emitting `numeric`; a present-but-null value failed every constraint instead of only `@NotNull` (Jakarta
  semantics); `#[Rules]` lost a custom `ValidationRule`'s constructor arguments on the compiled path, booting
  `new StartsWith()` where the developer wrote `new StartsWith('ACME')`. Rules now declare their arguments via
  `Compilable`, or have them recovered from promoted properties at COMPILE time, or are rejected then with an
  actionable message — never silently stripped at runtime.
- **`packages/config`** — `ProfileResolver` read raw `getenv()`, which returns `false` under both testbench and
  `config:cache`, so profiles collapsed to `['default']` exactly where they mattered; `#[Profile]` was
  declared, exported and documented with zero production readers.
- **`packages/observability` — `/actuator/metrics` and `/actuator/prometheus` were effectively empty in
  production.** Under PHP-FPM every request is a fresh process, so a scrape saw only what that scrape's own
  request recorded — worse than empty, because it reads as data. See `CacheMeterRegistry` above.
- **`packages/cli`** — `make:firefly-handler` generated code that made the next `firefly:cache` throw and abort
  the whole compile; `make:firefly-repository` generated an interface nothing could resolve;
  `make:firefly-listener` generated a class the scanner could not discover. Stub tests now generate from each
  stub and assert the output is valid PHP *and* discoverable by the relevant scanner.

## [26.07.18] - 2026-07-28

### Added
- **`samples/lumen`** — a runnable DDD wallet-and-ledger sample exercising the whole stack: a `Wallet` Eloquent aggregate (`HasDomainEvents`/`RecordsDomainEvents`) with a `balance >= 0` invariant, a `Money` value object, four domain events, a hexagonal `WalletRepository` port + Eloquent adapter, CQRS command/query handlers (`#[CommandHandler]`/`#[QueryHandler]`, `#[Transactional]`), a `LedgerProjector` (`#[EventListener]`), a genuine same-transaction outbox, `#[PreAuthorize]` method security, and a REST layer with RFC-7807 problem-details. A `firefly/lumen` path-package (never in the metapackage/split matrix/Deptrac paths). ~40 tests, all dispatching through the real `CommandBus`/`QueryBus`/router.
- **Book — *LaraFly by Example*** — a complete bilingual (EN + ES) book under `book/`: a quick start, 13 chapters across four parts (Foundations, Modelling & Persisting the Domain, Coordinating & Securing the App, Observability/Testing/Delivery), plus a Laravel→LaraFly cheat-sheet and a glossary. A dedicated Python/WeasyPrint pipeline renders PDF + EPUB in both languages; every fenced PHP listing is `php -l`-verified against the sample.
- **Tutorial** — an end-to-end, ~12-step guided tutorial (`docs/tutorial.md`) in English + Spanish (`docs/tutorial.es.md`).
- **Docs table of contents** (`docs/README.md`) grouping every guide by topic; grown `docs/index.md`.

### Changed
- **README** — rewritten to a professional, fully-explained front page (15 sections, banner + five SVG diagrams, nine source-accurate "Featured Patterns" showcases, real links to the tutorial/book/docs TOC), guarded by a relative-link test.

## [26.07.17] - 2026-07-28

### Added
- `firefly/installer` — the global `firefly new <app>` installer (laravel/installer analog): a thin `bin/firefly` Symfony Console binary with a single `NewCommand` wrapping `composer create-project firefly/skeleton`, git-init, and next-steps; `symfony/console`+`symfony/process` only (no firefly runtime deps). New Deptrac `Installer` layer (depends on nothing).
- Publish-readiness packaging: Apache-2.0 `LICENSE` on every package + skeleton; the 8 missing per-package READMEs; full Packagist metadata (`authors`/`keywords`/`homepage`/`support`) + `extra.branch-alias` (`dev-main` → `26.x-dev`) on all 26 units — still NO `version` field.
- Pre-push safety guard: `scripts/check-no-sensitive-tracked.sh` + a CI `guard` job failing on any tracked/staged `.superpowers/`, `docs/superpowers/`, `.claude`, `CLAUDE.md`, `.env`, or secret marker; hardened `.gitignore`.
- Docs overhaul (pyfly parity): top-level `installation.md`, `getting-started.md`, `cli.md`, `laravel-comparison.md`, `versioning.md`, `contributing.md`, `publishing.md`, `modules/installer.md`; five source-accurate SVG diagrams (boot pipeline, DI/auto-config, request lifecycle, same-tx outbox, CQRS/EDA bridge); a brand-clean LaraFly banner.
- Dormant `.github/workflows/release.yml` — on a pushed `v*` tag, subtree-splits all 26 units to `fireflyframework/firefly-<pkg>` read-only mirrors (`symplify/monorepo-split-github-action`).

### Changed
- Promoted `docs/modules/getting-started.md` → `docs/getting-started.md` and `docs/modules/cli.md` → `docs/cli.md` (top-level, pyfly-parity nav).

## [26.07.16] - 2026-07-27

### Added
- `firefly/eda-rabbitmq`, `firefly/eda-postgres`, `firefly/eda-kafka` — real message-broker adapters behind the M9 `EventPublisher` port.
- Genuine same-transaction outbox (`firefly_eda_outbox`) in `firefly/eda-postgres` — the outbox row commits atomically with the aggregate.
- `firefly/eda` consumer-loop SPI (`EventConsumer`/`ConsumerLoop`) + `firefly:eda:consume`; `firefly:outbox:relay`.
- Per-broker `HealthIndicator`s; `@group('integration')` round-trip tests per broker.

### Changed
- `firefly/data`: added an optional `PreCommitEventHook` seam to `Domain\DomainEventDispatcher` (non-frozen) enabling the same-tx outbox; null by default — no behaviour change for existing apps.

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

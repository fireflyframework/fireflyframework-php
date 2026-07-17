# Changelog

All notable changes to LaraFly are documented here. This project uses CalVer (`YY.MM.Patch`).

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

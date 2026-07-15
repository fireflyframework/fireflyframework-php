# Changelog

All notable changes to LaraFly are documented here. This project uses CalVer (`YY.MM.Patch`).

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

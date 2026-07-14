# Changelog

All notable changes to LaraFly are documented here. This project uses CalVer (`YY.MM.Patch`).

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

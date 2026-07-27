# LaraFly — Firefly Framework for PHP

[![CI](https://github.com/fireflyframework/fireflyframework-php/actions/workflows/ci.yml/badge.svg)](https://github.com/fireflyframework/fireflyframework-php/actions/workflows/ci.yml)
![Version](https://img.shields.io/badge/version-26.07.16-brightgreen)
![PHP](https://img.shields.io/badge/php-8.3%2B-blue)
![License](https://img.shields.io/badge/license-Apache%202.0-green)

**LaraFly** brings Spring Boot's cohesion — dependency injection with stereotypes, conditional
auto-configuration, hexagonal ports & adapters, CQRS, event-driven architecture, and a project CLI —
to **Laravel 13**. It is the PHP member of the Firefly Framework family (see also PyFly for Python).

> Development monorepo. Packages live under `packages/*` and publish to `firefly/*` on Packagist.

## Requirements
- PHP 8.3+ (8.4 recommended)

## Packages

The aggregator (`composer.json`) wires every `packages/*` directory as a local path repository. Runtime
packages: `firefly/kernel`, `firefly/container`, `firefly/config`, `firefly/context`, `firefly/autoconfigure`,
`firefly/validation`, `firefly/web`, `firefly/resilience`, `firefly/scheduling`, `firefly/scheduling-postgres`,
`firefly/domain`, `firefly/data`, `firefly/eda`, `firefly/eda-rabbitmq`, `firefly/eda-postgres`,
`firefly/eda-kafka`, `firefly/messaging`, `firefly/cqrs`, `firefly/security`,
`firefly/actuator`, `firefly/observability`, `firefly/firefly` (the runtime metapackage). Dev-scoped:
**`firefly/testing`** — the first-party test-support kit (see below) — and **`firefly/cli`** — the developer
console (see below). `firefly/skeleton` (a `type: project` create-project template) lives at the top-level
`skeleton/` directory, outside `packages/*`.

## Getting Started / CLI (`firefly/cli`)

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:cache
php artisan firefly:serve
```

`firefly/cli` is the developer-experience console: `firefly:cache`/`:clear` compile the app into a
zero-reflection cached boot, `firefly:about`/`:routes`/`:health`/`:metrics` introspect a booted app in-process
(actuator-over-CLI, no HTTP), the `make:firefly-*` family scaffolds every stereotype, and `firefly:serve`/`:db`
thinly delegate to Laravel's own commands. `firefly/firefly` is a one-line runtime metapackage
(`composer require firefly/firefly`). See:

- [Getting Started](docs/modules/getting-started.md) — the full quickstart.
- [CLI](docs/modules/cli.md) — the full command reference.

## Testing (`firefly/testing`)

`firefly/testing` is the dev-scoped test-support kit every package in this monorepo dogfoods: a boot harness
(`FireflyTestCase` + `bootFireflyApp()`/`fireflyApplication()`), sqlite/testcontainers database bases, web/data
slice builders (+ `#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` attributes), recording doubles for Firefly's own
ports, Firefly-flavored Pest expectations (`toHavePublished`, `toHaveHandledCommand`, `toBeUp`,
`toHaveRecordedMetric`, `toBeProblemDetails`), and a small fixture layer. See:

- [Testing](docs/modules/testing.md) — the full harness reference.
- [Integration Testing](docs/modules/integration-testing.md) — `@group integration`, `RequiresDocker`, and
  testcontainers.

## License
Apache-2.0 © Firefly Software Solutions Inc.

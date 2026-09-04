# Getting Started

## Quickstart: `firefly/skeleton`

The fastest way to a booting, cached LaraFly app is the `firefly/skeleton` create-project template — a Laravel 13
application pre-wired with the Firefly family, a `#[Controller]` welcome page, a sample
`#[RestController]`/`#[Service]` pair, and `firefly:cache` already wired into `post-create-project-cmd`:

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:cache
php artisan firefly:serve
```

`composer create-project` alone already ran `migrate` and `firefly:cache` for you (via
`post-create-project-cmd`), so the app boots reflection-free and its sample `POST /orders` persists from the
first request; re-run `firefly:cache` whenever you add or change
`#[Component]`/`#[RestController]`/`#[CommandHandler]`/etc. classes. `firefly:clear` drops back to the
in-process scan, which costs a reflection pass per boot but is functionally identical — every manifest
resolves to the compiled artifact if present, otherwise a scan of `firefly.scan.paths`, otherwise empty.

`firefly:serve` is a thin passthrough to `artisan serve` (or `octane:start` when `laravel/octane` is
installed) — see [CLI](cli.md) for the full command reference.

## Adding LaraFly to an existing Laravel app

Pull in the whole runtime family with one line — `firefly/firefly` is a Composer metapackage (the Maven BOM
analogue) that requires every runtime package, `firefly/cli` included, so `firefly:cache` and the
`make:firefly-*` generators are available straight away:

```bash
composer require firefly/firefly
```

The browser dashboard (`firefly/admin`) and the API-documentation package (`firefly/openapi`) come with it. The
broker adapters (`firefly/eda-rabbitmq`, `firefly/eda-postgres`, `firefly/eda-kafka`) and the test kit
(`firefly/testing`) stay separate — each binds you to an infrastructure choice or belongs in `require-dev`.

Then point LaraFly at your app's classes and compile it:

```php
// config/firefly.php
'scan' => [
    'paths' => [
        'App\\' => app_path(),
    ],
],
```

```bash
php artisan firefly:cache
php artisan firefly:serve
```

## The three new packages

- **`firefly/cli`** — the developer-experience console: `firefly:cache`/`:clear`, actuator-over-CLI
  `firefly:about`/`:routes`/`:health`/`:metrics`, the `make:firefly-*` generator family, and thin
  `firefly:serve`/`:db` passthroughs. See [CLI](cli.md).
- **`firefly/firefly`** — a `type: metapackage` runtime aggregator; `composer require firefly/firefly` pulls the
  whole runtime family in one line, `firefly/cli` among them. (It is in the metapackage deliberately: while it
  was `require-dev`-only, an application that never ran `firefly:cache` booted with empty manifests.)
- **`firefly/skeleton`** — a `type: project` Laravel 13 create-project template, pre-wired with the Firefly family
  and a sample `#[Controller]`/`#[RestController]`/`#[Service]` slice, that yields a booting, cached app
  straight out of `composer create-project`.

## Where to next

- [Architecture](architecture.md) — how the boot engine, DI, and auto-configuration fit together.
- [Auto-Configuration](modules/starters.md) — writing your own `#[Configuration]`/`#[Bean]` starters.
- [Testing](modules/testing.md) — the `firefly/testing` harness every package (and your app) dogfoods.

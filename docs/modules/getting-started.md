# Getting Started

## Quickstart: `firefly/skeleton`

The fastest way to a booting, cached LaraFly app is the `firefly/skeleton` create-project template — a Laravel 13
application pre-wired with the Firefly family, a sample `#[RestController]`/`#[Service]` pair, and
`firefly:cache` already wired into `post-create-project-cmd`:

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:cache
php artisan firefly:serve
```

`composer create-project` alone already ran `firefly:cache` for you (via `post-create-project-cmd`), so the app
boots reflection-free from the first request; re-run `firefly:cache` yourself whenever you add or change
`#[Component]`/`#[RestController]`/`#[CommandHandler]`/etc. classes, and `firefly:clear` to fall back to the
in-process scanner. `firefly:serve` is a thin passthrough to `artisan serve` (or `octane:start` when
`laravel/octane` is installed) — see [CLI](cli.md) for the full command reference.

## Adding LaraFly to an existing Laravel app

Pull in the whole runtime family with one line — `firefly/firefly` is a Composer metapackage (the Maven BOM
analogue) that requires every runtime package (`firefly/kernel` through `firefly/observability`):

```bash
composer require firefly/firefly
```

Add `firefly/cli` for the developer-experience console (`firefly:cache`, `make:firefly-*`, and friends):

```bash
composer require --dev firefly/cli
```

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
  whole runtime family in one line.
- **`firefly/skeleton`** — a `type: project` Laravel 13 create-project template, pre-wired with the Firefly family
  and a sample `#[RestController]`/`#[Service]`, that yields a booting, cached app straight out of
  `composer create-project`.

## Where to next

- [Architecture](../architecture.md) — how the boot engine, DI, and auto-configuration fit together.
- [Auto-Configuration](starters.md) — writing your own `#[Configuration]`/`#[Bean]` starters.
- [Testing](testing.md) — the `firefly/testing` harness every package (and your app) dogfoods.

# Installation

## Requirements

- **PHP 8.3+** (8.4 recommended).
- **Composer 2.**
- **Laravel 13** — LaraFly is a Composer library that layers onto a Laravel application; it is not a
  standalone runtime.

## Quick install

The bundled `firefly new` installer supplies the application template to Composer and prepares the app:

```bash
composer global require firefly/firefly
firefly new my-app
cd my-app
php artisan firefly:serve
```

`firefly new` takes an optional application name (it prompts when you omit one) and these options, read
off `NewCommand::configure()`:

| Option | What it does |
|---|---|
| `--api` / `--web` / `--full` | The archetype. `--web` is the default: the `#[Controller]` welcome page *and* the sample `#[RestController]`. `--api` is JSON only — no view layer, no welcome page. `--full` is the web archetype with every optional capability pre-wired. |
| `--with=` | Comma-separated capabilities to pre-wire, repeatable. Each one's own `requires` are pulled in transitively, so `--with=eda-kafka` cannot leave you with a Kafka adapter and no `EventPublisher` port for it to implement. Run `firefly new --help` for the live list — it is interpolated from the catalog, so it cannot drift. |
| `--dev` | Install the latest dev release of the family instead of a tagged one. |
| `-f`, `--force` | **Empties the target directory first**, then scaffolds into it. |
| `--git` / `--no-git` | Whether to `git init` and make an initial commit. On by default. |

Every component's code is included in `firefly/firefly`. `--with` records an explicit component
requirement in the generated manifest; it does not fetch a separate framework package. Configuration
selects the active transport. `eda-postgres` needs `ext-pdo_pgsql`, `eda-kafka` needs `ext-rdkafka`,
`eda-rabbitmq` uses the included AMQP client, and `scheduling-postgres` needs a PostgreSQL connection.
`--with=testing` adds `orchestra/testbench` and `illuminate/testing` to **`require-dev`**, alongside the
compatible `firefly/testing` requirement. See [Installer](modules/installer.md).

## Without the installer

From a framework source checkout, Composer can also use the local bundled template directly:

```bash
composer create-project --repository='{"type":"path","url":"./skeleton","options":{"symlink":false}}' firefly/skeleton my-app --stability=dev
cd my-app
php artisan firefly:serve
```

The generated application installs `firefly/firefly` from Packagist; before first publication, supply a
root VCS or path repository as described in [Publishing](publishing.md).

`firefly/skeleton`'s `composer.json` wires `post-create-project-cmd` to run automatically, so by the time the
command above finishes you already have, in this order:

1. `.env` copied from `.env.example`.
2. `database/database.sqlite` created (`touch`).
3. `php artisan key:generate`.
4. `php artisan migrate --force` — the sample resource persists into an `orders` table, so `POST /orders`
   works on the first request rather than after a step nobody told you about.
5. `php artisan firefly:cache` — the zero-reflection compile step (see below).

## What the template requires

`skeleton/composer.json` requires PHP, Laravel, `firefly/firefly` and `firefly/cli`. The library supplies
all framework code; the CLI requirement is satisfied by `replace`. Providers are discovered from the
root package's `extra.laravel.providers` metadata.

Two of those arrived in the `26.09` line and are worth naming, because both are **off until you configure
them** and neither costs you anything until then:

- **`firefly/security-oauth2-client`** — signing in *with* a provider, and calling APIs as one: registrations
  and providers spelled the way Spring Boot spells them, OIDC login with PKCE, an
  `OAuth2AuthorizedClientManager` and an `Http::oauth2Client()` macro. Gated by
  `firefly.security.oauth2.client.enabled`, default `false`. See
  [OAuth2 Client](modules/security-oauth2-client.md).
- **`firefly/security-oauth2-server`** — *being* the provider: an authorization server that issues
  authorization codes, access, refresh and id tokens, publishes a JWKS and answers introspection and
  revocation. Gated by `firefly.security.oauth2.server.enabled`, default `false`, and it needs a signing key —
  `php artisan firefly:oauth2:keys` writes one. See
  [OAuth2 Authorization Server](modules/security-oauth2-server.md).

## What you get

A runnable Laravel 13 application, already on the cached, zero-reflection boot path:

- **sqlite** for the database and the **array**/**sync** drivers for cache/queue — zero external services
  required to boot.
- A `#[Controller]` welcome page (the HTML stereotype) plus a sample `#[RestController]` + `#[Service]` pair
  wired end-to-end, so `php artisan firefly:serve` gives you a working page and a working JSON endpoint
  immediately. The welcome page is not a placeholder: it reads the real `BootPhase` enum, the same
  `BeansCatalog` and `ConditionEvaluationReport` the actuator serves, and the same `RouteManifest` the
  dispatcher reads, so it shows your application's own routes and whether this boot was compiled or scanned.
- A second, larger slice under `app/Orders/` and `app/Http/Order*.php`: a CRUD `#[RestController]` over
  `#[Repository]` beans with a validated `#[RequestBody]`. Delete it when you no longer need it.
- A sample `#[ConfigProperties]` DTO showing typed config binding.
- `config/firefly.php` as a full, commented reference of every `firefly.*` key the framework reads — the
  same file `tests/ConfigReferenceTest.php` holds the framework to, so a key the code reads and this file
  does not document is a failing build rather than a surprise.
- `bootstrap/cache/firefly/` already populated — every scanner→compiler pair (DI, routes, exception handlers,
  validation constraints, config properties, CQRS handlers, event/message listeners, scheduled tasks, security
  methods, the proxy plan and its generated proxies) has already run, so the first request boots with no
  reflection at all. Re-run `php artisan firefly:cache` whenever you add or change an annotated class; `php
  artisan firefly:clear` drops back to the in-process scan, which is slower per boot but functionally
  identical.

## Next steps

- [Getting Started](getting-started.md) — write your first controller/service and understand the request
  lifecycle.
- [CLI Reference](cli.md) — every `firefly:*` command and `make:firefly-*` generator.
- [Modules](modules.md) — the complete, grouped index of the module guides.

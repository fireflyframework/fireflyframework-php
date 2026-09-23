# Installation

## Requirements

- **PHP 8.3+** (8.4 recommended).
- **Composer 2.**
- **Laravel 13** — LaraFly is a set of Composer packages that layer onto a Laravel application; it is not a
  standalone runtime.

## Quick install

The fastest path is the global installer, `firefly/installer` — a thin `firefly new` binary (Symfony Console,
no Firefly runtime dependencies) that wraps `composer create-project firefly/skeleton`:

```bash
composer global require firefly/installer
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

`--with` never decides whether code *exists*: every non-adapter capability is already required by the
`firefly/firefly` metapackage, so naming one promotes an installed package to an explicit dependency in the
generated `composer.json` rather than fetching anything new. See [Installer](modules/installer.md) for the
full catalogue and how the tool is built.

## Without the installer

If you'd rather not install a global binary, `composer create-project` alone gets you the same result —
`firefly new` is a convenience wrapper around exactly this command:

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:serve
```

`firefly/skeleton`'s `composer.json` wires `post-create-project-cmd` to run automatically, so by the time the
command above finishes you already have, in this order:

1. `.env` copied from `.env.example`.
2. `database/database.sqlite` created (`touch`).
3. `php artisan key:generate`.
4. `php artisan migrate --force` — the sample resource persists into an `orders` table, so `POST /orders`
   works on the first request rather than after a step nobody told you about.
5. `php artisan firefly:cache` — the zero-reflection compile step (see below).

## What the template requires

`skeleton/composer.json` asks for exactly four things: `php: ^8.3`, `laravel/framework: ^13.0`,
`firefly/cli` and `firefly/firefly`. The last of those is the metapackage, and it is what pulls the whole
runtime family in:

<!-- source: packages/firefly/composer.json -->

```json
"require": {
    "php": "^8.3",
    "firefly/actuator": "*@dev",
    "firefly/admin": "*@dev",
    "firefly/autoconfigure": "*@dev",
    "firefly/cli": "*@dev",
// …
    "firefly/security": "*@dev",
    "firefly/security-oauth2-client": "*@dev",
    "firefly/security-oauth2-server": "*@dev",
    "firefly/validation": "*@dev",
    "firefly/web": "*@dev"
},
```

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

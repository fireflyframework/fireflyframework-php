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

`firefly new` accepts a target directory (defaults to prompting for one), `--force` to scaffold into a
non-empty directory, `--dev` to install the latest dev release of the family instead of a tagged release, and
`--git`/`--no-git` to control whether it `git init`s and makes an initial commit (on by default). See
[Installer](modules/installer.md) for the full flag reference and how it's built.

## Without the installer

If you'd rather not install a global binary, `composer create-project` alone gets you the same result —
`firefly new` is a convenience wrapper around exactly this command:

```bash
composer create-project firefly/skeleton my-app
cd my-app
php artisan firefly:serve
```

`firefly/skeleton`'s `composer.json` wires `post-create-project-cmd` to run automatically, so by the time the
command above finishes you already have:

1. `.env` copied from `.env.example`.
2. `database/database.sqlite` created (`touch`).
3. `php artisan key:generate`.
4. `php artisan firefly:cache` — the zero-reflection compile step (see below).

## What you get

A runnable Laravel 13 application, already on the cached, zero-reflection boot path:

- **sqlite** for the database and the **array**/**sync** drivers for cache/queue — zero external services
  required to boot.
- A sample `#[RestController]` + `#[Service]` pair wired end-to-end, so `php artisan firefly:serve` gives you
  a working HTTP endpoint immediately.
- A sample `#[ConfigProperties]` DTO showing typed config binding.
- `bootstrap/cache/firefly/` already populated — every scanner→compiler pair (DI, routes, config, CQRS
  handlers, event listeners, scheduled tasks, security methods, `#[Transactional]` proxies) has already run,
  so the first request boots with no reflection at all. Re-run `php artisan firefly:cache` whenever you add or
  change an annotated class; `php artisan firefly:clear` falls back to the in-process scanner.

## Next steps

- [Getting Started](getting-started.md) — write your first controller/service and understand the request
  lifecycle.
- [CLI Reference](cli.md) — every `firefly:*` command and `make:firefly-*` generator.

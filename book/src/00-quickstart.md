<span class="eyebrow">Quick Start</span>

# Build Lumen Step by Step {.chtitle}

Welcome. Before the deep dive, this chapter takes you from an *empty terminal* to a *running, curl-able* LaraFly application — installed, compiled, and served — in well under ten minutes. Every command and every listing here is real: it is exactly what `composer create-project firefly/skeleton` generates, unmodified.

This is a *tour*, not the deep dive. Chapter 1 makes the case for the whole approach; Chapter 2 opens the engine room and explains, in depth, everything you meet here only in passing — the container, the stereotypes, and the compiled boot manifest. The goal of this chapter is momentum: by the end of it you will have a real service running, and a first, informal feel for what "convention over configuration" means in LaraFly.

!!! note "Note"
    Every listing in this chapter is copied verbatim from `skeleton/`, the project scaffold `composer create-project firefly/skeleton` installs, and from `samples/lumen`, the fuller digital-wallet-and-ledger application this book builds toward over the chapters that follow.

---

## Step 1 — Prerequisites

LaraFly is a PHP framework built on Laravel 13. You need:

* **PHP 8.3 or newer**, with the extensions Laravel itself requires (`mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`, `json`).
* **Composer 2**, PHP's dependency manager.

Confirm both are available:

```bash
php --version
composer --version
```

---

## Step 2 — Install

The fastest way to start a new LaraFly application is `composer create-project`, pointed at the `firefly/skeleton` template:

```bash
composer create-project firefly/skeleton my-app
cd my-app
```

`composer create-project` does more than copy files: it runs a `post-create-project-cmd` script that finishes setting the project up for you —

```json
"post-create-project-cmd": [
    "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
    "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
    "@php artisan key:generate --ansi",
    "@php artisan firefly:cache"
]
```

By the time that command returns, `.env` exists, a SQLite database file has been touched, `APP_KEY` is set, and — the step that matters most for this book — **`firefly:cache` has already compiled your application's manifests**. You have not written a single line of PHP yet, and the zero-reflection boot path this whole framework is built around is already in place.

!!! tip "A global installer, if you prefer it"
    `composer global require firefly/installer` gives you a `firefly` command on your `PATH`. `firefly new my-app` wraps the same `composer create-project firefly/skeleton` call, then runs `git init` and an initial commit for you — the LaraFly equivalent of `laravel new`.

### What you just installed

The skeleton's `composer.json` requires only two Firefly packages directly:

```json
"require": {
    "php": "^8.3",
    "firefly/cli": "*@dev",
    "firefly/firefly": "*@dev",
    "laravel/framework": "^13.0"
}
```

`firefly/cli` gives you the `artisan firefly:*` commands you will use throughout this book. `firefly/firefly` is the **runtime metapackage** — the Composer analogue of a Maven BOM — that pulls in the whole Firefly family (container, context, config, web, data, cqrs, eda, security, validation, resilience, scheduling, observability, actuator, and more) in a single `require` line, so your own `composer.json` never has to enumerate them one at a time.

!!! laravel "Laravel parity"
    `composer create-project firefly/skeleton` is LaraFly's counterpart to `laravel new` — and `firefly/firefly` is the counterpart of installing `laravel/framework` itself: one dependency line that brings in an entire, coherent stack rather than a collection of independently-versioned pieces.

---

## Step 3 — A look inside

Open the generated project. Two things are worth noticing immediately, because they are the whole idea of this framework in miniature.

First, `bootstrap/providers.php` is empty:

```php
<?php

return [];
```

There is no service provider to register by hand. `firefly/cli` and `firefly/firefly`'s own providers are discovered automatically by Composer/Laravel package discovery — you never add a line here for a Firefly package.

Second, `routes/web.php` is almost empty too:

```php
<?php

// Intentionally minimal: Firefly's WebServiceProvider registers the app's #[RestController] routes from
// the compiled RouteManifest (see skeleton/app/Http/GreetingController.php). This file exists because
// bootstrap/app.php's withRouting(web: ...) requires the path.
```

Routes are not declared here at all. As the comment says, they come from a **compiled `RouteManifest`** — built from attributes on your controllers, not from a routes file you maintain by hand. You will meet the class that comment points to in a moment.

The one file that does matter right now is `config/firefly.php`:

```php
<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            'App\\' => app_path(),
        ],
    ],
    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],
];
```

`scan.paths` tells LaraFly's component scanner which PSR-4 roots to look at — here, just your own `App\` namespace under `app/`. `cache.path` is where `firefly:cache` (which you already ran, in Step 2) wrote the compiled manifests it found there.

---

## Step 4 — Meet the first bean

`app/GreetingProperties.php` is a small, typed configuration DTO — LaraFly's answer to Spring's `@ConfigurationProperties`:

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * Binds the `greeting.*` configuration subtree onto this readonly DTO and registers it as a container
 * singleton, so it can be constructor-injected wherever GreetingProperties is requested.
 */
#[ConfigProperties('greeting')]
final readonly class GreetingProperties
{
    public function __construct(public string $salutation = 'Hello') {}
}
```

`app/GreetingService.php` is a plain PHP class carrying one attribute, `#[Service]`:

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Container\Attributes\Service;

/**
 * A #[Service] stereotype: auto-registered as a singleton bean and resolved through the container, so its
 * GreetingProperties dependency is autowired.
 */
#[Service]
final class GreetingService
{
    public function __construct(private readonly GreetingProperties $properties) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }
}
```

Nothing here registers `GreetingService` with a container by hand, and nothing wires `GreetingProperties` into its constructor either. `#[Service]` is enough: the component scan finds the class, the container builds it, and because the constructor asks for a `GreetingProperties`, the container resolves and injects one automatically. This is **constructor dependency injection**, and it is the subject of the whole of Chapter 2.

---

## Step 5 — Expose it over HTTP

`app/Http/GreetingController.php` puts a web edge on the service:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\GreetingService;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RestController;

/**
 * The sample Firefly slice: a #[RestController] whose routes are discovered by the RouteScanner and served
 * from the compiled RouteManifest. GreetingService is autowired via constructor DI.
 */
#[RestController]
final class GreetingController
{
    public function __construct(private readonly GreetingService $greetings) {}

    /** @return array<string, string> */
    #[GetMapping('/')]
    public function index(): array
    {
        return ['message' => $this->greetings->greet('World')];
    }

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}', name: 'greetings.show')]
    public function show(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }
}
```

`#[RestController]` is itself a stereotype — it is built on the same `#[Component]` base as `#[Service]` — so `GreetingController` is autowired exactly like `GreetingService` was: the container builds it, sees the constructor wants a `GreetingService`, and supplies one. `#[GetMapping]` and `#[PathVariable]` are what the *route scan* reads, separately, to build the `RouteManifest` `routes/web.php`'s comment referred to.

---

## Step 6 — Run it

Start the development server:

```bash
php artisan firefly:serve
```

`firefly:serve` is a thin wrapper: it calls `artisan serve` (or `octane:start`, if `laravel/octane` happens to be installed) — it does not reimplement anything of its own. In another terminal, hit the two routes you just read:

```bash
curl -s localhost:8000/
```

```json
{"message":"Hello, World!"}
```

```bash
curl -s localhost:8000/greetings/Ada
```

```json
{"message":"Hello, Ada!"}
```

`"Hello"` is `GreetingProperties`'s default `$salutation` — nothing in `config/greeting.php` overrides it yet, so the constructor default is what you see. Change that default, or bind `greeting.salutation` in your own config, and every response reflects it — with no code change to either the service or the controller.

::: figure art/figures/request-lifecycle.svg | Figure 0.1 — A request travels through the web filter chain and the controller dispatcher before your handler method ever runs.

!!! note "Two commands, one job"
    You already ran `firefly:cache` once, indirectly, during `composer create-project`. If you add or change an attribute — a new `#[Service]`, a new route — re-run `php artisan firefly:cache` to recompile the manifests, or `php artisan firefly:clear` to delete them and fall back to the (slower, reflection-based) development scan path. Chapter 2 explains exactly what each command produces.

---

## Step 7 — A glimpse of where this is going

`GreetingService` is deliberately the smallest possible slice: one bean, one dependency, one route. The application this book actually builds, chapter by chapter, is bigger — **Lumen**, a digital-wallet-and-ledger service that already exists, fully built and tested, in the framework's own `samples/lumen` package. It exposes:

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/wallets` | Open a wallet |
| POST | `/api/v1/wallets/{id}/deposit` | Deposit funds |
| POST | `/api/v1/wallets/{id}/withdraw` | Withdraw funds — secured |
| POST | `/api/v1/wallets/transfers` | Transfer funds between two wallets |
| GET | `/api/v1/wallets/{id}/balance` | Fetch just the balance |
| GET | `/api/v1/wallets/{id}/ledger` | Fetch the wallet's projected ledger |

Every one of those endpoints is built from the same handful of ideas you just met in miniature — a stereotyped class, constructor injection, an attribute the framework reads at scan time — plus a great deal more: a hexagonal repository port and its Eloquent adapter, CQRS command and query handlers, domain events projected into a ledger, and method-level security on the withdraw endpoint. You can run its whole test suite right now, before reading another page, if you want to see it working:

```bash
composer test -- samples/lumen/tests
```

The rest of this book builds exactly that application with you, one concept at a time, starting with the idea you have already used twice without a full explanation: the container.

---

## What you built {.recap}

You went from an empty terminal to a compiled, running, curl-able LaraFly application, and you met — without yet needing the full explanation — a `#[Service]`, a `#[ConfigProperties]` DTO, a `#[RestController]`, constructor dependency injection, and the `firefly:cache` compile step that turns all of it into a zero-reflection boot manifest.

| In this Quick Start you… | Goes deep in |
|---|---|
| Installed via `composer create-project firefly/skeleton` | **Chapter 1** — Why LaraFly? |
| Saw `#[Service]`, `#[ConfigProperties]`, and `#[RestController]` register beans with no manual wiring | **Chapter 2** — Dependency Injection & Auto-Configuration |
| Ran `firefly:cache` and saw routes served from a compiled manifest, not `routes/web.php` | **Chapter 2** — Dependency Injection & Auto-Configuration |

When you are ready for the *why* behind all of it, turn the page to Chapter 1.

---

## Try it yourself {.exercises}

1. **Change the default salutation.** Edit `GreetingProperties`'s constructor default from `'Hello'` to something else, re-run `php artisan firefly:cache`, restart `firefly:serve`, and confirm the response changes with no other code touched.
2. **Add a third route.** Give `GreetingController` a `farewell(string $name)` method mapped to `#[GetMapping('/farewells/{name}')]` that returns `['message' => "Goodbye, {$name}!"]` directly — no new service needed yet.
3. **Read the Lumen sample's `README.md`** (`samples/lumen/README.md`) before Chapter 1 — it previews, in prose, everything the rest of this book builds in code.

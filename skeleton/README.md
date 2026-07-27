# LaraFly Skeleton

A [Laravel 13](https://laravel.com) application skeleton pre-wired with the **Firefly** framework family
(`firefly/firefly` + `firefly/cli`). It ships a sample slice — a `#[RestController]`, a `#[Service]`, and a
`#[ConfigProperties]` DTO — plus the `firefly:cache` compile step in `post-create-project-cmd`, so a freshly
created app boots on the zero-reflection cached path with **zero external infrastructure** (sqlite + array/sync
drivers by default).

## Create a new app

```bash
composer create-project firefly/skeleton my-app
cd my-app
```

`composer create-project` runs `post-create-project-cmd`, which:

1. copies `.env.example` to `.env`,
2. touches the default `database/database.sqlite`,
3. runs `php artisan key:generate` to set `APP_KEY`,
4. runs `php artisan firefly:cache` to compile the app manifests into `bootstrap/cache/firefly/`.

## The sample slice

- `app/Http/GreetingController.php` — a `#[RestController]` exposing `GET /` and `GET /greetings/{name}`.
- `app/GreetingService.php` — a `#[Service]` autowired into the controller.
- `app/GreetingProperties.php` — a `#[ConfigProperties('greeting')]` DTO bound from configuration.
- `app/Support/CachedTransactionalConfiguration.php` — the committed `#[Configuration]`/`#[Bean]` that loads the
  compiled `TransactionalManifest` on a cached boot.

## Recompile the manifests

After adding or changing Firefly-annotated classes under `app/`, re-run:

```bash
php artisan firefly:cache
```

Run `php artisan firefly:clear` to remove the compiled cache and fall back to the dev-scan boot path.

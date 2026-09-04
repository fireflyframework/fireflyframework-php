# LaraFly Skeleton

A [Laravel 13](https://laravel.com) application skeleton pre-wired with the **Firefly** framework family
(`firefly/firefly` + `firefly/cli`). It ships a sample slice — a `#[Controller]` welcome page, a
`#[RestController]`, a `#[Service]`, and a `#[ConfigProperties]` DTO — plus the `firefly:cache` compile step in
`post-create-project-cmd`, so a freshly created app boots on the zero-reflection cached path with **zero
external infrastructure** (sqlite + array/sync drivers by default).

## Create a new app

```bash
composer create-project firefly/skeleton my-app
cd my-app
```

`composer create-project` runs `post-create-project-cmd`, which:

1. copies `.env.example` to `.env`,
2. touches the default `database/database.sqlite`,
3. runs `php artisan key:generate` to set `APP_KEY`,
4. runs `php artisan migrate` to create the `orders` table the sample resource stores into,
5. runs `php artisan firefly:cache` to compile the app manifests into `bootstrap/cache/firefly/`.

## The sample slice

- `app/Http/WelcomeController.php` — a `#[Controller]` (the HTML stereotype) rendering `GET /`. Nothing on the
  page is hard-coded: the boot pipeline, the bean and condition counts, the route table and the actuator's
  registered-vs-exposed endpoints all come from the same objects the actuator endpoints serve.
- `app/Http/GreetingController.php` — a `#[RestController]` exposing `GET /greetings/{name}`, which negotiates
  to JSON. The pair is the difference between the two stereotypes.
- `app/GreetingService.php` — a `#[Service]` autowired into the controller.
- `app/GreetingProperties.php` — a `#[ConfigProperties('greeting')]` DTO bound from configuration.

The second slice is a full REST resource, and it is what `php artisan make:firefly-controller OrderController`
generates, filled in:

- `app/Http/OrderController.php` — five actions on `/orders` (`GET` collection, `GET` one, `POST`, `PUT`,
  `DELETE`) from one class-level `#[RequestMapping]`. The `201` and the `204` are declared on their mappings,
  the paging parameters are bound and coerced by `#[QueryParam]`, and nothing in the class handles a missing
  order — `OrderService` throws, and `firefly/web` renders the whole exception taxonomy as RFC-7807
  `problem+json` at the exception's own status.
- `app/Http/OrderRequest.php`, `AddressPayload.php`, `OrderLinePayload.php` — the request body, a nested DTO
  and a list of DTOs. `#[Valid]` runs the compiled constraints *before* hydration, so an invalid body is a 422
  naming `shipTo.postcode` and never reaches an action. These are also what `/openapi.json` publishes as
  component schemas, `$ref`s and all.
- `app/Orders/` — the domain and the store. `Order`/`Address`/`OrderLine` are immutable value objects,
  `OrderEntity` is the Eloquent row, and `OrderRepository` is the interesting one: `extends
  EloquentRepository` plus a model name, and every CRUD method is inherited. `OrderService` maps between the
  two shapes and is the only place that knows both exist.
- `database/migrations/` — the `orders` table, created for you by `post-create-project-cmd`.
- `tests/Feature/` — `WelcomeTest` is the smoke test a new application should start from (HTML renders, JSON
  negotiates, `/actuator/health` reports UP); `OrderTest` drives the whole resource, response *and* row. Run
  both with `composer test`.

Because `OrderRepository` implements `CrudRepository`, the dashboard's data browser lists orders as a
browsable resource the moment you set `firefly.admin.data.enabled` — see `config/firefly.php`. Delete
`app/Orders`, `app/Http/Order*`, `app/Http/AddressPayload.php` and the migration to remove the sample.

## Configuration

`config/firefly.php` is the full reference: every `firefly.*` key the framework reads, grouped by capability,
with its real default and what it does. Keys a typical app never touches are commented out with their default
shown, so an absent key and a key set to the printed value behave identically. `.env.example` carries the
handful that usually differ per environment.

## Recompile the manifests

After adding or changing Firefly-annotated classes under `app/`, re-run:

```bash
php artisan firefly:cache
```

`php artisan firefly:clear` removes the compiled cache.

### What happens without the cache

The app still works. Every manifest — routes, exception handlers, CQRS handlers, event and message listeners,
scheduled tasks, validation constraints, method-security rules, `#[ConfigProperties]` DTOs and the
`#[Transactional]` proxies — is resolved the same way: **the compiled artifact if it exists, otherwise an
in-process scan of `firefly.scan.paths` on every boot, otherwise empty**. Compiling buys a reflection-free
boot; it is an optimisation, not a correctness requirement.

That is worth stating precisely, because this file used to claim the fallback while it did not exist: before
it landed, a `firefly:clear`ed app 404'd every route it owned, and — because method security reads "no rule
recorded for this method" as ALLOW — an empty security manifest silently disabled every `#[PreAuthorize]`.
Set `firefly.security.method.strict` (or `FIREFLY_SECURITY_METHOD_STRICT=true`) to make a boot with no
compiled method-security manifest refuse to start rather than run unprotected. The welcome page reports which
path this boot took.

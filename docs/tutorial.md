# Tutorial: Build Your First LaraFly Feature

This is a hand-built, step-by-step walkthrough of building a real LaraFly feature from an empty
`composer create-project` to a fully attribute-driven slice — a REST controller, a service, typed
configuration, a repository-backed domain model, request validation, RFC-7807 error handling, a CQRS
command/query pair, and a domain event listener. Every code block below is accurate to the framework as
shipped in this repository — attributes, class names, and method signatures are taken verbatim from
`firefly/*` package sources and the runnable `samples/lumen` wallet sample, not invented for the tutorial.

By the end you will have grown the `firefly/skeleton` starter's sample `Greeting` slice into a small
"greetings" feature with a persisted `greetings` table, an `#[EventListener]` reacting to a domain event,
and a zero-reflection cached boot — the same architecture the [Lumen sample](#step-12-whats-next) uses at
larger scale.

## Table of Contents

1. [Step 1: Create the App](#step-1-create-the-app)
2. [Step 2: Your First Endpoint — `#[RestController]`](#step-2-your-first-endpoint-restcontroller)
3. [Step 3: Autowiring a Service — `#[Service]`](#step-3-autowiring-a-service-service)
4. [Step 4: Typed Configuration — `#[ConfigProperties]`](#step-4-typed-configuration-configproperties)
5. [Step 5: Run It and Call the API](#step-5-run-it-and-call-the-api)
6. [Step 6: Persisting Greetings — `#[Repository]` and Derived Queries](#step-6-persisting-greetings-repository-and-derived-queries)
7. [Step 7: Validating the Request Body — `#[Valid]`](#step-7-validating-the-request-body-valid)
8. [Step 8: RFC-7807 Error Handling](#step-8-rfc-7807-error-handling)
9. [Step 9: Commands and Queries — CQRS](#step-9-commands-and-queries-cqrs)
10. [Step 10: Reacting to a Domain Event — `#[EventListener]`](#step-10-reacting-to-a-domain-event-eventlistener)
11. [Step 11: The Zero-Reflection Cache and Health Introspection](#step-11-the-zero-reflection-cache-and-health-introspection)
12. [Step 12: What's Next](#step-12-whats-next)

## Prerequisites

- **PHP 8.3+** (8.4 recommended) and **Composer 2**.
- A **terminal** and a code editor.
- `curl` (or any HTTP client) to exercise the endpoints as you build them.

See [Installation](installation.md) for the full requirements list.

---

## Step 1: Create the App

Scaffold a new LaraFly application with the `firefly/skeleton` Composer template:

```bash
composer create-project firefly/skeleton my-app
cd my-app
```

`firefly/skeleton`'s `post-create-project-cmd` runs automatically and leaves you with a booting,
already-cached app: it copies `.env.example` to `.env`, touches `database/database.sqlite`, runs
`php artisan key:generate`, runs `php artisan migrate` (the sample resource stores into an `orders` table,
so `POST /orders` works on the first request rather than after a step you have to be told about), and runs
`php artisan firefly:cache` — the zero-reflection compile step you'll
revisit in [Step 11](#step-11-the-zero-reflection-cache-and-health-introspection). See
[Installation](installation.md) for the equivalent `firefly new my-app` global-installer shortcut.

The scaffolded project looks like this:

```
my-app/
├── app/
│   ├── GreetingProperties.php     # #[ConfigProperties('greeting')] DTO
│   ├── GreetingService.php        # #[Service] bean
│   └── Http/
│       └── GreetingController.php # #[RestController]
├── bootstrap/
│   ├── app.php
│   ├── cache/firefly/              # compiled manifests — already populated
│   └── providers.php
├── config/
│   ├── app.php
│   ├── database.php
│   └── firefly.php                 # scan paths + cache paths
├── database/
│   └── database.sqlite
├── routes/
│   ├── console.php
│   └── web.php
├── artisan
└── composer.json
```

| File/Directory | Purpose |
|---|---|
| `app/` | Your application code — controllers, services, domain classes, everything LaraFly scans. |
| `config/firefly.php` | Tells LaraFly's component scan and `firefly:cache` where your classes live. |
| `bootstrap/cache/firefly/` | The compiled manifests `firefly:cache` writes — the zero-reflection boot path. |
| `database/database.sqlite` | The default database — no external services required to boot. |

Open `config/firefly.php`:

```php
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

`scan.paths` is a PSR-4 map (prefix → directory) — every `#[Component]`-family attribute (`#[Service]`,
`#[Repository]`, `#[RestController]`, `#[CommandHandler]`, …) under `app_path()` is discovered from here,
either by an in-process scan during development or, once compiled, from the `cache` manifests. The
skeleton ships one slice already wired end-to-end: `app/Http/GreetingController.php`, backed by
`app/GreetingService.php` and `app/GreetingProperties.php`. The rest of this tutorial grows that slice.

---

## Step 2: Your First Endpoint — `#[RestController]`

Open `app/Http/GreetingController.php` — this is what `firefly new` already generated for you:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\GreetingService;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RestController;

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

Element by element:

- **`#[RestController]`** — a stereotype that extends `Firefly\Container\Attributes\Component`. Because
  the component scan discovers stereotypes with `ReflectionAttribute::IS_INSTANCEOF`, a `#[RestController]`
  class is *both* auto-registered as a singleton DI bean *and* routed — no separate route registration.
- **`#[GetMapping('/')]`** — maps `GET /` to `index()`. `#[GetMapping]`/`#[PostMapping]`/`#[PutMapping]`/
  `#[PatchMapping]`/`#[DeleteMapping]` each accept `path`, `status` (default response status), and an
  optional Laravel route `name`.
- **`#[GetMapping('/greetings/{name}', name: 'greetings.show')]`** with **`#[PathVariable]`** — the
  `{name}` route segment is bound to the `$name` parameter automatically.
- A **plain array return** (`array<string, string>`) is JSON-encoded by the framework's content negotiation
  — no `Response` object to build by hand.

A separate `RouteScanner` pass reads the routing metadata off the same class the component scan already
found — a `#[RestController]` never registers its own routes.

---

## Step 3: Autowiring a Service — `#[Service]`

Open `app/GreetingService.php` — also already generated:

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Container\Attributes\Service;

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

**`#[Service]`** is a `#[Component]` stereotype — semantically "business logic," registered as a singleton
DI bean exactly like `#[RestController]`. The constructor asks for a `GreetingProperties` instance; the
container inspects `__construct()`'s type hints and resolves it automatically — no factory, no XML, no
manual `bind()` call. `GreetingController`'s own constructor works the same way: it asks for
`GreetingService`, and the container wires it in.

---

## Step 4: Typed Configuration — `#[ConfigProperties]`

Open `app/GreetingProperties.php`:

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Config\Attributes\ConfigProperties;

#[ConfigProperties('greeting')]
final readonly class GreetingProperties
{
    public function __construct(public string $salutation = 'Hello') {}
}
```

**`#[ConfigProperties('greeting')]`** binds the `greeting` config subtree (i.e. `config('greeting')`) onto
this readonly DTO and registers the *bound instance* as a container singleton, so it can be injected
wherever `GreetingProperties` is requested — exactly what `GreetingService` does above. There is no
`config/greeting.php` file in the skeleton, so `config('greeting')` resolves to an empty subtree; the
binder falls back to each constructor parameter's own default (`'Hello'`) rather than failing. This is why
the endpoint already greets with "Hello" out of the box.

To override it, add a real Laravel config file — `config/greeting.php`:

```php
<?php

declare(strict_types=1);

return [
    'salutation' => env('GREETING_SALUTATION', 'Hello'),
];
```

And in `.env`:

```
GREETING_SALUTATION="Howdy"
```

Nothing framework-specific here — `#[ConfigProperties]` binds from whatever `config('greeting')` resolves
to, and Laravel's own `config/*.php` + `env()` convention is how you populate it. No `firefly:cache`
re-run is needed for a plain config-value change (only for adding or changing annotated classes — see
[Step 11](#step-11-the-zero-reflection-cache-and-health-introspection)).

---

## Step 5: Run It and Call the API

Start the development server:

```bash
php artisan firefly:serve
```

`firefly:serve` is a thin passthrough to `artisan serve` (or `octane:start` if `laravel/octane` is
installed), listening on `127.0.0.1:8000` by default. In another terminal:

```bash
curl http://127.0.0.1:8000/
# {"message":"Hello, World!"}

curl http://127.0.0.1:8000/greetings/Ada
# {"message":"Hello, Ada!"}
```

If you set `GREETING_SALUTATION="Howdy"` in [Step 4](#step-4-typed-configuration-configproperties), the
same two requests now return `"Howdy, World!"` and `"Howdy, Ada!"` — no restart required, since
`config('greeting')` is re-read from the environment on each request.

---

## Step 6: Persisting Greetings — `#[Repository]` and Derived Queries

So far `greet()` is stateless. Let's persist named greetings to the database.

First, generate a migration (a plain Laravel command — nothing Firefly-specific here):

```bash
php artisan make:migration create_greetings_table
```

Edit the generated `database/migrations/..._create_greetings_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greetings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greetings');
    }
};
```

Run it — `firefly:db` is a thin passthrough to Laravel's own database commands (default action `migrate`):

```bash
php artisan firefly:db
```
```
INFO  Running migrations.

  2026_07_28_120500_create_greetings_table ... DONE
```

Now add the Eloquent model, `app/Domain/Greeting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain;

use Illuminate\Database\Eloquent\Model;

final class Greeting extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'message'];
}
```

And a repository, `app/Infrastructure/GreetingRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Greeting;
use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<Greeting>
 * @method Greeting|null findFirstByName(string $name)
 */
#[Repository]
final class GreetingRepository extends EloquentRepository
{
    protected string $model = Greeting::class;
}
```

Element by element:

- **`#[Repository]`** — another `#[Component]` stereotype (same family as `#[Service]`): a container bean,
  discovered the same way.
- **`EloquentRepository`** is `firefly/data`'s Eloquent-backed base implementing the CRUD/paging ports
  (`save()`, `findById()`, `findAll()`, `count()`, …). Setting `protected string $model` is the only thing
  a concrete repository needs to declare — the rest comes from the parent.
- **`findFirstByName(string $name)`** is never implemented — it doesn't need to be. It's a **derived
  query**: `EloquentRepository::__call()` parses the method name (`DerivedQueryParser`, pure string
  parsing, no reflection) into `find` (prefix) + `First` (limit 1, returns a nullable single result rather
  than a list) + `Name` (mapped to the `name` column, implicit `Equals` operator), and drives
  `Greeting::query()->where('name', '=', $name)->first()`. The `@method` docblock is only there so PHPStan
  sees a typed return for what is, at runtime, dynamic dispatch.

Wire the repository into the service, updating `app/GreetingService.php`:

```php
<?php

declare(strict_types=1);

namespace App;

use App\Domain\Greeting;
use App\Infrastructure\GreetingRepository;
use Firefly\Container\Attributes\Service;

#[Service]
final class GreetingService
{
    public function __construct(
        private readonly GreetingProperties $properties,
        private readonly GreetingRepository $repository,
    ) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }

    public function record(string $name, string $message): Greeting
    {
        return $this->repository->save(new Greeting(['name' => $name, 'message' => $message]));
    }

    public function findRecord(string $name): ?Greeting
    {
        return $this->repository->findFirstByName($name);
    }
}
```

And expose it, adding a `store()` method to `app/Http/GreetingController.php`:

```php
use App\Web\Dto\CreateGreetingRequest;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;

// ...inside GreetingController...

    /** @return array<string, string> */
    #[PostMapping('/greetings', status: 201)]
    public function store(#[RequestBody] CreateGreetingRequest $body): array
    {
        $greeting = $this->greetings->record($body->name, $body->message);

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
```

with a plain request DTO, `app/Web/Dto/CreateGreetingRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Web\Dto;

final class CreateGreetingRequest
{
    public function __construct(
        public readonly string $name,
        public readonly string $message,
    ) {}
}
```

`#[RequestBody]` decodes the JSON body and hydrates `CreateGreetingRequest` from it. Try it:

```bash
curl -X POST http://127.0.0.1:8000/greetings \
  -H "Content-Type: application/json" \
  -d '{"name": "Ada", "message": "Hello from the database!"}'
# {"name":"Ada","message":"Hello from the database!"}
```

Nothing stops an empty body from being saved, though:

```bash
curl -X POST http://127.0.0.1:8000/greetings \
  -H "Content-Type: application/json" \
  -d '{"name": "", "message": ""}'
# {"name":"","message":""}   <- saved! nothing validates this yet.
```

We'll fix that next.

---

## Step 7: Validating the Request Body — `#[Valid]`

Add constraints to `app/Web/Dto/CreateGreetingRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Size;

final class CreateGreetingRequest
{
    public function __construct(
        #[NotBlank]
        #[Size(min: 2, max: 40)]
        public readonly string $name,
        #[NotBlank]
        public readonly string $message,
    ) {}
}
```

And gate the controller parameter with `#[Valid]`, in `app/Http/GreetingController.php`:

```php
use Firefly\Validation\Valid;

    #[PostMapping('/greetings', status: 201)]
    public function store(#[Valid] #[RequestBody] CreateGreetingRequest $body): array
    {
        $greeting = $this->greetings->record($body->name, $body->message);

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
```

Element by element:

- **`#[NotBlank]`** (from `Firefly\Validation\Constraint`) rejects `null`, `''`, and an all-whitespace
  string.
- **`#[Size(min: 2, max: 40)]`** bounds string length.
- **`#[Valid]`** on the `#[RequestBody]` parameter runs the raw decoded body through
  `Firefly\Validation\Constraint\BeanValidator` against the DTO's compiled constraint rules **before** the
  DTO is constructed. Only on success is `CreateGreetingRequest` hydrated — a failure throws the kernel's
  `ValidationException` and the controller method body never runs.

Re-run the invalid request:

```bash
curl -X POST http://127.0.0.1:8000/greetings \
  -H "Content-Type: application/json" \
  -d '{"name": "", "message": ""}'
```

```json
{
    "status": 422,
    "title": "Unprocessable Entity",
    "code": "VALIDATION_ERROR",
    "category": "validation",
    "severity": "warning",
    "detail": "Validation failed",
    "errors": [
        { "field": "name", "message": "The name field is required." },
        { "field": "message", "message": "The message field is required." }
    ]
}
```

served as `application/problem+json`, with a `422` status — the same shape [Step 8](#step-8-rfc-7807-error-handling)
covers in detail. The valid request from Step 6 still works exactly as before.

---

## Step 8: RFC-7807 Error Handling

Add a lookup endpoint for a saved greeting, in `app/Http/GreetingController.php`:

```php
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}/record')]
    public function showRecord(#[PathVariable] string $name): array
    {
        $greeting = $this->greetings->findRecord($name);
        if ($greeting === null) {
            throw new ResourceNotFoundException("No saved greeting for '{$name}'");
        }

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
```

Every framework exception extends `Firefly\Kernel\Exception\FireflyException`, which fixes an HTTP status,
a stable machine-readable `errorCode()`, an `ErrorCategory`, and an `ErrorSeverity`.
`Firefly\Kernel\Exception\Business\ResourceNotFoundException` is a `404`/`RESOURCE_NOT_FOUND` typed
subclass — you never write a try/catch in the controller. `firefly/web`'s
`Firefly\Web\Exception\ProblemDetailsRenderer` turns any thrown `FireflyException` into an
`application/problem+json` response, globally, at the exception's own `httpStatus()`.

Try it:

```bash
curl http://127.0.0.1:8000/greetings/Ada/record
# {"name":"Ada","message":"Hello from the database!"}

curl -i http://127.0.0.1:8000/greetings/Nowhere/record
```

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

```json
{
    "status": 404,
    "title": "Not Found",
    "code": "RESOURCE_NOT_FOUND",
    "category": "business",
    "severity": "warning",
    "detail": "No saved greeting for 'Nowhere'",
    "instance": "greetings/Nowhere/record"
}
```

You did not write this renderer, a status-code table, or an error-response class — it is one consistent
mechanism across the whole framework. See [Error Handling](modules/error-handling.md) for the full
exception taxonomy (`ConflictException`, `AuthenticationException`, `AuthorizationException`, and more).

---

## Step 9: Commands and Queries — CQRS

For a read, `findRecord()` from Step 6 is fine. But a *mutation* — renaming a saved greeting — is a good
candidate for `firefly/cqrs`: it gets transactional semantics and domain-event publication for free.

First, teach the `Greeting` model to raise a domain event when renamed. Update `app/Domain/Greeting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Event\GreetingRenamed;
use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Model;

final class Greeting extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    /** @var list<string> */
    protected $fillable = ['name', 'message'];

    public function rename(string $newMessage): void
    {
        $old = $this->message;
        $this->message = $newMessage;
        $this->raiseEvent(new GreetingRenamed($this->name, $old, $newMessage));
    }
}
```

`Firefly\Domain\HasDomainEvents` is the trait form of `Firefly\Domain\AggregateRoot`'s event-buffering
behaviour (`raiseEvent()`/`pendingEvents()`/`pullEvents()`/`clearEvents()`) for a class whose single
inheritance slot is already spent on Eloquent's own `Model` — implementing `RecordsDomainEvents` alongside
it gives you one object that is both a persisted row *and* an event-raising aggregate. (A pure, non-Eloquent
domain object would `extend AggregateRoot` directly instead — see [Domain (DDD)](modules/domain.md).)

Add the event, `app/Domain/Event/GreetingRenamed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('greeting.events')]
final readonly class GreetingRenamed extends DomainEvent
{
    public function __construct(
        public string $name,
        public string $oldMessage,
        public string $newMessage,
    ) {
        parent::__construct();
    }
}
```

`#[PublishDomainEvent('greeting.events')]` routes this event to the `greeting.events` integration-event
destination once it's bridged onto the eda bus after commit (see [Step 10](#step-10-reacting-to-a-domain-event-eventlistener)).

Now the write side — a command and its handler. `app/Application/Command/RenameGreeting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class RenameGreeting
{
    public function __construct(public string $name, public string $newMessage) {}
}
```

`app/Application/Command/RenameGreetingHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Infrastructure\GreetingRepository;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

/**
 * Intentionally NOT final: the generated #[Transactional] proxy subclasses this handler.
 */
#[CommandHandler]
class RenameGreetingHandler
{
    public function __construct(private readonly GreetingRepository $greetings) {}

    #[Transactional]
    public function handle(RenameGreeting $command): void
    {
        $greeting = $this->greetings->findFirstByName($command->name);
        if ($greeting === null) {
            throw new ResourceNotFoundException("No saved greeting for '{$command->name}'");
        }

        $greeting->rename($command->newMessage);
        $this->greetings->save($greeting);
    }
}
```

And the read side — a query and its handler. `app/Application/Query/GetGreeting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class GetGreeting
{
    public function __construct(public string $name) {}
}
```

`app/Application/Query/GetGreetingHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Greeting;
use App\Infrastructure\GreetingRepository;
use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class GetGreetingHandler
{
    public function __construct(private readonly GreetingRepository $greetings) {}

    public function handle(GetGreeting $query): ?Greeting
    {
        return $this->greetings->findFirstByName($query->name);
    }
}
```

Element by element:

- **`#[CommandHandler]`** / **`#[QueryHandler]`** both specialise `#[Component]` — one attribute both
  registers the handler as a constructor-injected DI bean *and* tells `HandlerScanner` how to build the
  command/query → handler manifest. Each handler exposes exactly one public `handle()` method; the handled
  message type is inferred from that method's sole parameter (pass an explicit class-string,
  `#[CommandHandler(RenameGreeting::class)]`, only if you need to disambiguate).
- **`#[Transactional]`** on `handle()` is load-bearing, not decorative: a generated proxy wraps the real
  call in a transaction, and after a successful commit it drains `Greeting`'s buffered domain events and
  publishes them — without it, `GreetingRenamed` would never be raised onto the eda bus. See
  [Transactions](modules/transactional.md).
- `RenameGreetingHandler` is **not** `final` — the framework generates
  `RenameGreetingHandler__FireflyTransactionalProxy extends RenameGreetingHandler`, which would fatal at
  class-load time if the parent were `final`.

Wire both buses into the controller and swap `showRecord()` over to `QueryBus::ask()`, updating
`app/Http/GreetingController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Command\RenameGreeting;
use App\Application\Query\GetGreeting;
use App\GreetingService;
use App\Web\Dto\CreateGreetingRequest;
use App\Web\Dto\RenameGreetingRequest;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PatchMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class GreetingController
{
    public function __construct(
        private readonly GreetingService $greetings,
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

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

    /** @return array<string, string> */
    #[PostMapping('/greetings', status: 201)]
    public function store(#[Valid] #[RequestBody] CreateGreetingRequest $body): array
    {
        $greeting = $this->greetings->record($body->name, $body->message);

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}/record')]
    public function showRecord(#[PathVariable] string $name): array
    {
        /** @var \App\Domain\Greeting|null $greeting */
        $greeting = $this->queries->ask(new GetGreeting($name));
        if ($greeting === null) {
            throw new ResourceNotFoundException("No saved greeting for '{$name}'");
        }

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }

    /** @return array<string, string> */
    #[PatchMapping('/greetings/{name}/record')]
    public function rename(#[PathVariable] string $name, #[Valid] #[RequestBody] RenameGreetingRequest $body): array
    {
        $this->commands->send(new RenameGreeting($name, $body->message));

        /** @var \App\Domain\Greeting $greeting */
        $greeting = $this->queries->ask(new GetGreeting($name));

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
}
```

with one more small DTO, `app/Web/Dto/RenameGreetingRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;

final class RenameGreetingRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $message,
    ) {}
}
```

**`CommandBus::send(object $command): mixed`** is the write side; **`QueryBus::ask(object $query): mixed`**
is the read side (`ask`, not `query`, to avoid colliding with Eloquent's own `query()`). Both run a bounded
pipeline (correlate → validate → authorize → resolve + invoke handler → metrics) before reaching your
handler. Try it:

```bash
curl -X PATCH http://127.0.0.1:8000/greetings/Ada/record \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello again from the database!"}'
# {"name":"Ada","message":"Hello again from the database!"}
```

---

## Step 10: Reacting to a Domain Event — `#[EventListener]`

`GreetingRenamed` is a domain event, raised in-process by `Greeting::rename()`. Once
`RenameGreetingHandler`'s `#[Transactional]` unit of work commits, `firefly/cqrs`'s domain → integration-event
bridge re-publishes it onto the `firefly/eda` broker bus as an integration event — `eventType` becomes the
event's short class name (`"GreetingRenamed"`), and `destination` comes from `#[PublishDomainEvent]`
(`"greeting.events"`).

Add a listener, `app/Listener/GreetingRenamedListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Illuminate\Support\Facades\Log;

#[Component]
final class GreetingRenamedListener
{
    #[EventListener('GreetingRenamed')]
    public function onGreetingRenamed(EventEnvelope $envelope): void
    {
        Log::info('greeting.renamed', $envelope->payload);
    }
}
```

Element by element:

- **`#[Component]`** — a plain bean; the listener needs no HTTP or CQRS stereotype, just DI registration.
- **`#[EventListener('GreetingRenamed')]`** subscribes to the eda broker bus, **not** the in-process
  application-event bus (that's the unrelated `#[AsEventListener]` from `firefly/context`). The pattern is
  matched against the envelope's `eventType` with `fnmatch()` — a plain string like `'GreetingRenamed'`
  behaves as an exact match; you could also pass a glob (`'Greeting*'`) or an array of several exact names.
  The pattern must name the event **type**, never the `#[PublishDomainEvent]` destination string.
- **`EventEnvelope`** carries `eventType`, `destination`, `payload` (the event's public fields —
  `name`, `oldMessage`, `newMessage`, plus `DomainEvent`'s own `eventId`/`occurredAt`), and `headers`.

With the skeleton's default in-memory eda provider, delivery is synchronous — the listener runs before the
`PATCH` request in Step 9 even returns. Re-run the `PATCH` from Step 9 and check the log:

```bash
tail -n 1 storage/logs/laravel.log
```

```
[2026-07-28 12:10:03] local.INFO: greeting.renamed {"name":"Ada","oldMessage":"Hello from the database!","newMessage":"Hello again from the database!", ...}
```

See [Event-Driven Architecture](modules/eda.md) for the full broker/adapter model and
[CQRS](modules/cqrs.md) for exactly how the domain → integration-event bridge triggers.

---

## Step 11: The Zero-Reflection Cache and Health Introspection

Every class you've added since Step 1 — the repository, the command/query handlers, the event listener —
needs to be compiled into the app's manifests before it's picked up on a cached boot. Re-run:

```bash
php artisan firefly:cache
```

```
firefly:cache — wrote 12 manifest(s) + 1 proxy(ies) to /path/to/my-app/bootstrap/cache/firefly
```

This single command drives *every* settled package's existing scanner → compiler pair over
`config('firefly.scan.paths')` and writes, into `bootstrap/cache/firefly/`: the DI/context/config-properties
manifests, the compiled route table, the validation constraint manifest, the CQRS handler manifest, the
event/message-listener manifests, the scheduled-task manifest, the security-method manifest, the
`#[Transactional]` manifest, **and** a generated `RenameGreetingHandler__FireflyTransactionalProxy` class
file (the "1 proxy" above — one per `#[Transactional]` target in your app). A
`FireflyCacheServiceProvider` binds these compiled manifests ahead of any bean resolution, so every request
after this runs against plain PHP arrays — no runtime reflection on the hot path. Run it again any time you
add or change an annotated class; `php artisan firefly:clear` deletes the cache and falls back to the
in-process scanner.

Now introspect the running app at the terminal, in-process — no HTTP round trip:

```bash
php artisan firefly:health
```

```json
{
    "status": "UP"
}
```

`firefly:health` resolves the same `ActuatorRegistry` a `GET /actuator/health` HTTP request would and
prints its response — the CLI reimplements no actuator logic of its own. Try the HTTP route too:

```bash
curl http://127.0.0.1:8000/actuator/health
# {"status":"UP"}

curl -i http://127.0.0.1:8000/actuator/env
# HTTP/1.1 404 Not Found   <- sensitive endpoints are unexposed until you opt in
```

See [CLI Reference](cli.md) for every `firefly:*` command and `make:firefly-*` generator (including
`make:firefly-handler`, `make:firefly-listener`, and `make:firefly-repository`, which scaffold the exact
stereotypes you just hand-wrote), and [Actuator](modules/actuator.md) for the full endpoint list and
exposure/security model.

---

## Step 12: What's Next

You've built one small feature slice — `#[RestController]` → `#[Service]` → `#[ConfigProperties]` →
`#[Repository]` → `#[Valid]` → RFC-7807 errors → `#[CommandHandler]`/`#[QueryHandler]` →
`#[EventListener]` → a cached, zero-reflection boot — using nothing but the framework's own real,
shipped attributes. From here:

### Foundation

- [Dependency Injection](modules/dependency-injection.md) — the full `#[Component]` family, scopes,
  conditional beans, and how the compiled manifest works.
- [Configuration](modules/configuration.md) — profiles, `#[Value]`, and nested `#[ConfigProperties]` DTOs.
- [Validation](modules/validation.md) — the full constraint catalogue (financial-domain rules included).

### Web, Data, and Domain

- [Web Layer](modules/web.md) — parameter binding (`#[QueryParam]`, `#[RequestHeader]`, `#[UploadedFile]`),
  content negotiation, and the full `#[Valid]` cascade model.
- [Data & Repositories](modules/data.md) — the full derived-query grammar, `#[Query]`, `Specification`s,
  and pagination.
- [Relational Data](modules/data-relational.md) and [Transactions](modules/transactional.md) — everything
  `EloquentRepository` and `#[Transactional]` do beyond this tutorial (propagation modes, isolation,
  rollback rules).
- [Domain (DDD)](modules/domain.md) — `Entity`, `ValueObject`, `AggregateRoot`, and the full after-commit
  event model.
- [Error Handling](modules/error-handling.md) — the complete exception taxonomy and `#[ExceptionHandler]`/
  `#[ControllerAdvice]`.

### CQRS, Events, and Messaging

- [CQRS (Command/Query)](modules/cqrs.md) — the bounded pipeline, the domain → integration-event bridge in
  full, and authorization/caching seams.
- [Event-Driven Architecture](modules/eda.md) — retry/DLQ policy, the queue adapter, and the two-event-surfaces
  distinction (`#[AsEventListener]` vs. `#[EventListener]`).
- [EDA Brokers](modules/eda-brokers.md) and [Messaging](modules/messaging.md) — real broker adapters and the
  lower-level raw-bytes `MessageBrokerPort`.

### Production

- [Security](modules/security.md) — authentication, deny-by-default `HttpSecurity`, and `#[PreAuthorize]`.
- [Actuator](modules/actuator.md) and [Observability](modules/observability.md) — the full endpoint set and
  the Prometheus/Micrometer-style metrics core.
- [Resilience](modules/resilience.md) and [Scheduling](modules/scheduling.md) — `Retry`/`CircuitBreaker`/
  `Bulkhead` and `#[Scheduled]`.
- [Testing](modules/testing.md) — the `firefly/testing` boot harness and recording doubles for every port
  used in this tutorial.

### See It at Scale

The [Lumen sample](https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen) is a
runnable digital-wallet & ledger vertical slice exercising the exact same primitives you just used —
`#[Transactional]`, CQRS, domain events over EDA, method security, and RFC-7807 error rendering — at the
scale of a full aggregate with multiple commands, queries, and a read-model projector.

---

Apache-2.0 © Firefly Software Solutions Inc.

<span class="eyebrow">Part I — Foundations · Chapter 4</span>

# Your First HTTP API {.chtitle}

By the end of this chapter you will know how `#[RestController]` and `#[RequestMapping]` turn a plain class into a routed HTTP surface, how each of the five parameter-binding attributes pulls one piece of a request into a typed method argument, how `#[Valid]` gates a request body on Bean-Validation constraints before your handler ever runs, and how every error — a validation failure, a missing wallet, a denied authorization check — renders as the same predictable `application/problem+json` shape. This chapter closes Part I by turning Lumen's wallet domain into a real, validated REST API.

!!! note "New term: dispatch"
    **Dispatch** is the act of routing an incoming HTTP request to the right method on the right controller, with the right arguments already extracted and converted. In LaraFly this happens through native Laravel routes — there is no separate LaraFly router competing with Laravel's own.

---

## `#[RestController]` and `#[RequestMapping]`

You already know, from Chapter 2, that `#[RestController]` is itself a `#[Component]` stereotype — it `extends Firefly\Container\Attributes\Component`, so a controller is discovered by the very same component scan as a `#[Service]` or a `#[Repository]`, and gets full constructor dependency injection for free. What Chapter 2 deferred is the *other* pass a `#[RestController]` participates in: a separate reflection walk, `RouteScanner`, that reads a different set of attributes on the same class to build the routing table.

Here is Lumen's whole wallet API — the running example for the rest of this chapter — in one real, shipped listing:

```php
<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Query\GetBalance;
use Lumen\Application\Query\GetLedger;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;
use Lumen\Web\Dto\AmountRequest;
use Lumen\Web\Dto\OpenWalletRequest;
use Lumen\Web\Dto\TransferRequest;

/**
 * The wallet REST surface: thin HTTP-onto-CQRS mapping, no business logic. Every method builds a
 * command/query, dispatches it through the bus, and shapes the return array — the same rule the sample's
 * CQRS handlers already enforce for the domain layer. A domain/business fault raised by the bus (e.g. an
 * unknown wallet's ResourceNotFoundException, an overdraw's ConflictException, or a denied #[PreAuthorize]
 * on withdraw) surfaces as CommandProcessingException/QueryProcessingException and renders RFC-7807
 * problem-details via the framework's global ProblemDetailsRenderer — no local #[ExceptionHandler] needed.
 */
#[RestController]
#[RequestMapping('/api/v1/wallets')]
final class WalletController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    /** @return array{wallet_id: string} */
    #[PostMapping(status: 201)]
    public function open(#[Valid] #[RequestBody] OpenWalletRequest $body): array
    {
        /** @var string $id */
        $id = $this->commands->send(new OpenWallet($body->owner_id, Currency::from($body->currency)));

        return ['wallet_id' => $id];
    }

    /** @return array{wallet_id: string, balance_minor: int} */
    #[PostMapping('/{id}/deposit')]
    public function deposit(#[PathVariable] string $id, #[Valid] #[RequestBody] AmountRequest $body): array
    {
        /** @var int $balance */
        $balance = $this->commands->send(new Deposit($id, $body->amount_minor));

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /**
     * Debits `amount_minor` from the wallet. Guarded upstream at the bus: WithdrawHandler carries
     * #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")] (S6), enforced by
     * SecurityCommandAuthorizer BEFORE the handler runs. Without an authorized principal in the
     * SecurityContextHolder, the bus denies the command and the AuthorizationException it wraps renders
     * as a 403 problem-details response — this endpoint is secured, not broken.
     *
     * @return array{wallet_id: string, balance_minor: int}
     */
    #[PostMapping('/{id}/withdraw')]
    public function withdraw(#[PathVariable] string $id, #[Valid] #[RequestBody] AmountRequest $body): array
    {
        /** @var int $balance */
        $balance = $this->commands->send(new Withdraw($id, $body->amount_minor));

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /**
     * Moves `amount_minor` from the source wallet to the destination wallet as one atomic unit of work
     * (TransferHandler's #[Transactional] boundary), then reads back both fresh balances for the response.
     *
     * @return array{source_wallet_id: string, destination_wallet_id: string, source_balance_minor: int, destination_balance_minor: int}
     */
    #[PostMapping('/transfers')]
    public function transfer(#[Valid] #[RequestBody] TransferRequest $body): array
    {
        $this->commands->send(new Transfer($body->source_wallet_id, $body->destination_wallet_id, $body->amount_minor));

        /** @var int $sourceBalance */
        $sourceBalance = $this->queries->ask(new GetBalance($body->source_wallet_id));
        /** @var int $destinationBalance */
        $destinationBalance = $this->queries->ask(new GetBalance($body->destination_wallet_id));

        return [
            'source_wallet_id' => $body->source_wallet_id,
            'destination_wallet_id' => $body->destination_wallet_id,
            'source_balance_minor' => $sourceBalance,
            'destination_balance_minor' => $destinationBalance,
        ];
    }

    /** @return array{wallet_id: string, balance_minor: int} */
    #[GetMapping('/{id}/balance')]
    public function balance(#[PathVariable] string $id): array
    {
        /** @var int|null $balance */
        $balance = $this->queries->ask(new GetBalance($id));
        if ($balance === null) {
            throw new ResourceNotFoundException("Wallet {$id} not found");
        }

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /** @return array{wallet_id: string, entries: list<LedgerEntry>} */
    #[GetMapping('/{id}/ledger')]
    public function ledger(#[PathVariable] string $id): array
    {
        /** @var list<LedgerEntry> $entries */
        $entries = $this->queries->ask(new GetLedger($id));

        return ['wallet_id' => $id, 'entries' => $entries];
    }
}
```

Four things about this class are worth pointing out before anything else. First, `#[RequestMapping('/api/v1/wallets')]` is class-level and prepends its path to every method mapping below it — `open()` maps `POST /api/v1/wallets`, `deposit()` maps `POST /api/v1/wallets/{id}/deposit`. Second, the controller's constructor asks for `CommandBus` and `QueryBus` — the same constructor-injection story Chapter 2 covered, applied to a controller instead of a service. Third, every method is a thin translation: build a command or query DTO, hand it to the bus, shape the array the framework will serialize as JSON. There is no business logic here — no overdraft check, no currency comparison — because that logic lives in the `Wallet` aggregate and its command handlers, which Chapters 5 and 6 build. Fourth, the docblock is not decoration: it tells you, in the same file where the routes live, exactly which failures each endpoint can produce and how they will be rendered — the subject of this chapter's final section.

!!! laravel "Laravel parity"
    `#[RestController]` + `#[RequestMapping]` + the five verb-mapping attributes below are LaraFly's counterpart to Spring's `@RestController` + `@RequestMapping` + `@GetMapping`/`@PostMapping`. In a plain Laravel application the corresponding artifact is a `routes/api.php` entry pointing at a controller method; here, the route *is* the attribute, declared on the method it dispatches to, so there is exactly one place to look for both.

---

## The verb-mapping attributes

Each method carries exactly one verb attribute, and all five implement the same `Mapping` marker interface, so `RouteScanner` discovers them polymorphically — with `ReflectionAttribute::IS_INSTANCEOF`, the same trick Chapter 2 used for stereotypes — rather than needing separate scanner code per verb:

| Attribute | HTTP method |
|---|---|
| `#[GetMapping]` | GET |
| `#[PostMapping]` | POST |
| `#[PutMapping]` | PUT |
| `#[PatchMapping]` | PATCH |
| `#[DeleteMapping]` | DELETE |

Every one of the five accepts the same three constructor arguments — a relative `path` (joined to the class-level base), an integer `status` (the default response status: 200 for the read verbs, and `status: 201` on `WalletController::open()` above, since opening a wallet is a creation), and an optional route `name`:

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class GetMapping implements Mapping
{
    public function __construct(
        public readonly string $path = '',
        public readonly int $status = 200,
        public readonly ?string $name = null,
    ) {}

    public function method(): string
    {
        return 'GET';
    }
}
```

`PostMapping`, `PutMapping`, `PatchMapping`, and `DeleteMapping` are the same shape, each simply returning its own HTTP method string from `method()`.

---

## Parameter binding: five attributes, two conventions

Chapter 2 explained how the container resolves a *bean's* constructor. A controller *method's* parameters are resolved differently — by `ArgumentResolver`, which reads one binding attribute per parameter and pulls exactly one piece of the request into it:

| Attribute | Source | Notes |
|---|---|---|
| `#[PathVariable(name?)]` | route segment | always required; defaults to the parameter's own name |
| `#[QueryParam(name?, default?, required?)]` | query string | not required by default |
| `#[RequestBody]` | request body | decoded via the negotiated `MessageConverter`; combine with `#[Valid]` to gate on validation |
| `#[RequestHeader(name?, default?)]` | HTTP header | not required |
| `#[UploadedFile(name?)]` | `multipart/form-data` file | a framework-neutral `Firefly\Web\Http\UploadedFile` (filename/mimeType/size + lazy `contents()`/`store()`) |

`WalletController::balance()`, in the full listing above, shows the simplest of these: `#[GetMapping('/{id}/balance')]` paired with `#[PathVariable] string $id`, which reads the `{id}` route segment straight into a typed string parameter.

`open()` and `deposit()` show the combination you will use on every write endpoint — `#[Valid] #[RequestBody]` stacked on the same parameter, covered in full in the next section.

Two more parameter shapes need **no attribute at all**, and both are genuine conventions rather than omissions. A parameter typed as a class with no binding attribute — a service interface, for instance — resolves as a plain **container service**, exactly as `$container->make($type)` would; this lets a method reach for an extra collaborator without adding it to the controller's own constructor. A **scalar** parameter with no binding attribute defaults to a query parameter keyed by its own name, required unless the parameter itself has a default or is nullable.

Path and query values are coerced to the parameter's declared scalar type — `int`, `float`, or `bool` — and an uncoercible value, or a missing required one, raises `InvalidRequestException` (HTTP 400, with codes `MISSING_PARAMETER` / `TYPE_CONVERSION_ERROR`), rendered through the same problem-details pipeline this chapter ends with.

---

## The request DTOs

Every write endpoint in `WalletController` accepts a small, real Pydantic-style — here, plain-PHP — DTO. All three live in `samples/lumen/src/Web/Dto/` and are pure constructor-promoted classes with per-property validation constraints:

```php
<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\CurrencyCode;
use Firefly\Validation\Constraint\NotBlank;

/**
 * Validated request body for `POST /api/v1/wallets`: an owner id and an ISO-4217 currency code. `#[Valid]` on the
 * controller parameter runs BeanValidator against these constraints BEFORE the DTO is hydrated (ArgumentResolver),
 * so an invalid body never reaches WalletController::open() — it renders as a 422 RFC-7807 payload instead.
 */
final class OpenWalletRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $owner_id,
        #[NotBlank]
        #[CurrencyCode]
        public readonly string $currency,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\Positive;

/**
 * Validated request body shared by `POST /api/v1/wallets/{id}/deposit` and `.../withdraw`: a strictly positive
 * amount in minor units. Both Deposit and Withdraw take an existing wallet id from the path and only the amount
 * from the body, so one DTO covers both endpoints.
 */
final class AmountRequest
{
    public function __construct(
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Positive;

/**
 * Validated request body for `POST /api/v1/wallets/transfers`: both wallet ids travel in the body (a transfer is
 * not scoped under a single wallet's path) plus a strictly positive amount in minor units.
 */
final class TransferRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $source_wallet_id,
        #[NotBlank]
        public readonly string $destination_wallet_id,
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
```

`AmountRequest` doing double duty for both deposit and withdraw is a deliberate design choice, not laziness — both operations move a positive amount of money in minor units, and the wallet id already lives in the path, not the body, so a single shared DTO keeps the two endpoints' contracts identical without a shared base class.

---

## `#[Valid]` → `BeanValidator` → 422

`#[Valid]`, from `firefly/validation`, is what turns those per-property attributes from inert metadata into an enforced gate. Stacking it onto a `#[RequestBody]` parameter — as every write method on `WalletController` does — tells the dispatcher: validate the *raw decoded body* against this DTO class's compiled constraint rules **before** constructing the DTO at all.

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Valid {}
```

The validation itself runs through `Firefly\Validation\Constraint\BeanValidator`, which delegates to a `Validator` port (`IlluminateValidator` by default) using rules a `ConstraintManifest` compiled ahead of time from each constraint attribute's `toRules()` method:

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class NotBlank implements Constraint
{
    public function toRules(): array
    {
        return ['required', 'string', 'regex:/\S/'];
    }
}
```

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Positive implements Constraint
{
    public function toRules(): array
    {
        return ['numeric', 'gt:0'];
    }
}
```

Every constraint attribute reduces, this way, to one or more plain Illuminate validation rules — `#[NotBlank]` rejects `null`, `''`, `[]`, and an all-whitespace string; `#[Positive]` requires a numeric value strictly greater than zero; `#[CurrencyCode]` (used on `OpenWalletRequest::$currency`) delegates to a dedicated `Currency` rule object rather than a rule string. On failure, `BeanValidator` throws the kernel's `ValidationException` — the same exception type this chapter's final section maps to HTTP 422 — carrying one `FieldError` per failed property. On success, the DTO is constructed from the **raw** decoded body, not from a validator-produced subset, so a property with no constraint attribute at all is never silently dropped.

!!! note "Note"
    Constraints validate the raw request array, not the constructed DTO. This ordering matters: `#[Valid]` guarantees your handler method only ever receives a DTO whose constrained fields have already passed every check — there is no path where `WalletController::open()` runs with an `OpenWalletRequest` whose `owner_id` is blank.

!!! laravel "Laravel parity"
    `#[Valid] #[RequestBody]` is LaraFly's counterpart to Spring's `@Valid @RequestBody` pair. In a plain Laravel application the closest equivalent is a Form Request class with a `rules()` method; `firefly/validation`'s per-property attributes move those same rules onto the DTO's own fields, so the constraint and the field it constrains are declared in the same place.

`firefly/validation` ships sixteen financial-domain constraints beyond the general-purpose ones (`#[NotBlank]`, `#[NotEmpty]`, `#[NotNull]`, `#[Size]`, `#[Min]`/`#[Max]`, `#[Pattern]`, `#[Email]`, and more): `#[Iban]`, `#[Bic]`, `#[Swift]`, `#[Isin]`, `#[Cusip]`, `#[RoutingNumber]`, `#[Luhn]`, `#[CurrencyCode]`, `#[CountryCode]`, `#[LanguageTag]`, `#[UuidValue]`, `#[Phone]`, `#[PostalCode]`, `#[Percentage]`, `#[Money]`, and `#[DecimalScale]` — plus a `#[Rules]` escape hatch for any raw Illuminate rule string or `ValidationRule` object you need that no dedicated attribute covers yet.

---

## Content negotiation

Once a handler returns, something still has to decide the wire format. `firefly/web` ships one `MessageConverter` today — `JsonMessageConverter`, matching `application/json` and any `+json` suffix type — chosen from the request's `Accept` header (parsed for q-values, with a header-order tiebreak) and falling back to JSON when nothing matches or the header is absent. Request bodies are read the same way, keyed off `Content-Type`. `MessageConverterRegistry` is an ordinary, guarded container binding, so an application can register additional converters — XML support is a deliberately deferred seam, not a shipped feature.

---

## Errors that clients can trust

A well-designed API never leaks a raw stack trace, and never returns a bare 500 for something a caller could have avoided. `firefly/kernel`'s exception hierarchy is the backbone that makes this automatic: every framework exception extends `FireflyException`, which carries a stable `errorCode()`, an `httpStatus()`, a `category()`, and a `severity()` — so raising the right *kind* of exception is the only HTTP concern your handler code ever has to think about.

| Exception | Code | Status |
|---|---|---|
| `Business\ResourceNotFoundException` | `RESOURCE_NOT_FOUND` | 404 |
| `Business\ConflictException` | `CONFLICT` | 409 |
| `Business\ValidationException` | `VALIDATION_ERROR` | 422 |
| `Security\AuthenticationException` | `AUTHENTICATION_FAILED` | 401 |
| `Security\AuthorizationException` | `ACCESS_DENIED` | 403 |
| `Infrastructure\ServiceUnavailableException` | `SERVICE_UNAVAILABLE` | 503 |

`WalletController::balance()` raises the first of these directly, exactly the way any handler should:

```php
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

throw new ResourceNotFoundException("Wallet {$id} not found");
```

Chapter 6's `Wallet` aggregate raises `ConflictException` for an overdraft or a currency mismatch, and `WithdrawHandler`'s `#[PreAuthorize]` (Chapter 6 briefly, security in full later) can deny a request before the handler body ever runs, wrapping an `AuthorizationException`. All three reach the same renderer.

### The RFC-7807 renderer

`Firefly\Web\Exception\ProblemDetailsRenderer` is where every one of those exceptions actually becomes bytes on the wire:

```php
final class ProblemDetailsRenderer
{
    public function render(Throwable $e, Request $request): Response
    {
        $exception = match (true) {
            $e instanceof FireflyException => $e,
            $e instanceof HttpExceptionInterface => new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : self::statusText($e->getStatusCode()),
                self::errorCode($e->getStatusCode()),
                $e->getStatusCode(),
                ErrorCategory::Framework,
                ErrorSeverity::Warning,
                $e,
            ),
            default => new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
                'INTERNAL_ERROR',
                500,
                ErrorCategory::Internal,
                ErrorSeverity::Error,
                $e,
            ),
        };

        $payload = ErrorResponse::fromException(
            $exception,
            instance: $request->path(),
            timestamp: (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        )->toArray();

        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $exception->httpStatus(),
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
```

Three cases fold onto the same response shape. A `FireflyException` (or one of its typed subclasses, like `ResourceNotFoundException`) renders as-is, at its own `httpStatus()`. A Laravel/Symfony routing exception — a URL with no matching route at all — is converted while **preserving its real status code**, so an unmatched route still answers 404, never a misleading 500. Anything else — a genuinely unexpected `Throwable` — becomes a generic, category-`Internal`, HTTP-500 `FireflyException`. Every path ends at the same `ErrorResponse::fromException(...)->toArray()` call, so the payload shape never depends on which branch produced it.

Requesting a wallet that was never opened renders like this:

```json
{
  "status": 404,
  "title": "Not Found",
  "code": "RESOURCE_NOT_FOUND",
  "category": "business",
  "severity": "warning",
  "detail": "Wallet wlt-999 not found",
  "instance": "/api/v1/wallets/wlt-999",
  "timestamp": "2026-06-07T10:30:00+00:00"
}
```

A failed `#[Valid]` check on `POST /api/v1/wallets` — an empty `owner_id` — additionally carries an `errors` array, one entry per failed field:

```json
{
  "status": 422,
  "title": "Unprocessable Entity",
  "code": "VALIDATION_ERROR",
  "category": "validation",
  "severity": "warning",
  "detail": "Validation failed",
  "instance": "/api/v1/wallets",
  "errors": [
    {"field": "owner_id", "message": "is required"}
  ]
}
```

And a withdraw attempt with no authenticated `ADMIN` or `WALLET_OWNER` principal — denied by `WithdrawHandler`'s `#[PreAuthorize]` before the handler body ever runs — renders at 403, with no code change to `WalletController::withdraw()` at all:

```json
{
  "status": 403,
  "title": "Forbidden",
  "code": "ACCESS_DENIED",
  "category": "security",
  "severity": "warning",
  "detail": "Access is denied",
  "instance": "/api/v1/wallets/wlt-1/withdraw"
}
```

### Handling an exception yourself

Before an exception reaches the generic renderer, LaraFly gives your application a chance to answer it directly. `#[ExceptionHandler(SomeException::class)]` marks a method as the renderer for one exception class (or any subclass) — either declared **on the controller that threw it**, or on a dedicated `#[ControllerAdvice]` bean for a **global** handler shared across every controller:

```php
use Firefly\Web\Attributes\ControllerAdvice;
use Firefly\Web\Attributes\ExceptionHandler;
use Lumen\Domain\Exception\WalletFrozenException;

#[ControllerAdvice]
final class WalletExceptionAdvice
{
    #[ExceptionHandler(WalletFrozenException::class)]
    public function onFrozen(WalletFrozenException $e): array
    {
        return ['code' => 'WALLET_FROZEN', 'message' => $e->getMessage()];
    }
}
```

A controller-local handler always beats a global `#[ControllerAdvice]` one for the same exception; within whichever scope wins, the most-specific matching class in the hierarchy is chosen. A matched handler's return value is content-negotiated exactly like any other controller return, but rendered at the exception's own `httpStatus()` rather than the route's default. If nothing matches at either scope, the exception falls through to `ProblemDetailsRenderer` — so every unhandled error, however it started, still ends up as `application/problem+json`, never an uncaught framework error page.

::: figure art/figures/request-lifecycle.svg | Figure 4.1 — A request travels through the web filter chain, route dispatch, argument binding and validation, and — on failure at any point — the same RFC-7807 renderer, before your handler method ever runs.

---

## The compiled `RouteManifest`

You have now seen the same shape twice — Chapter 2's component manifest, this chapter's config-properties manifest — and routing follows it a third time. `RouteScanner` is the one reflection site in `packages/web/src`: it walks `#[RestController]` classes, and for each verb-mapped method compiles a `RouteDescriptor` — the HTTP method, the joined path, the controller and method name, the default status, an optional route name, and a pure-array parameter-binding plan. `RouteManifestCompiler` `var_export()`s the result to `bootstrap/cache/firefly/routes.php`; `RouteManifest::load()` reads it back with no reflection at all.

At boot, `RouteWiringPass` registers one **native Laravel route** per descriptor and hands each one a dispatch closure through `ControllerDispatcher` — so route listing, URL generation, and Laravel's own `route:cache` all apply to `WalletController`'s routes exactly as they would to any hand-written `routes/api.php` entry, because that is genuinely what they are, once `firefly:cache` has run.

---

## What you built {.recap}

Part I is complete. Lumen now **boots** (Chapter 1), is **wired** (Chapter 2's constructor injection with zero glue code), is **configured** (Chapter 3's typed, profile-aware settings), and **serves** — a validated REST API at `/api/v1/wallets` with:

| Piece | What it does |
|---|---|
| `#[RestController]` + `#[RequestMapping]` | Registers the controller as a bean and fixes its URL prefix |
| `#[GetMapping]`/`#[PostMapping]`/… | One verb attribute per method, compiled into a `RouteDescriptor` |
| `#[PathVariable]` / `#[QueryParam]` / `#[RequestBody]` / `#[RequestHeader]` / `#[UploadedFile]` | Five ways to pull one piece of the request into a typed parameter |
| `#[Valid]` | Gates a `#[RequestBody]` DTO on its compiled constraint rules, throwing `ValidationException` on failure |
| `FireflyException` hierarchy | Typed exceptions carrying their own error code, HTTP status, category, and severity |
| `ProblemDetailsRenderer` | Turns any exception into a consistent `application/problem+json` response |
| `#[ExceptionHandler]` / `#[ControllerAdvice]` | An escape hatch to render a specific exception yourself, locally or globally |

Chapter 5 replaces nothing in `WalletController` — the controller you just read stays exactly as it is — and instead builds the repository layer the command handlers behind `CommandBus`/`QueryBus` persist through.

---

## Try it yourself {.exercises}

1. **Add a `DELETE` endpoint.** In a scratch project (not the shipped `samples/lumen` package), add `#[DeleteMapping('/{id}', status: 204)]` to a new `WalletController` method that sends a hypothetical `CloseWallet` command and returns `null`. Confirm the route compiles and that a `204` with no body is what a matching request produces.
2. **Trace a validation failure end to end.** Send `POST /api/v1/wallets` with `{"owner_id": "", "currency": "XYZ"}` against a running Lumen instance and read the resulting `application/problem+json` body. Match each `errors` entry back to the constraint attribute on `OpenWalletRequest` that produced it.
3. **Write a `#[ControllerAdvice]`.** Add a global exception handler for `Firefly\Kernel\Exception\Business\ConflictException` that returns a custom `{"code": "...", "hint": "..."}` shape instead of the default problem-details payload, and confirm it applies to every controller in your scratch project, not just one.

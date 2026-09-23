# Error Handling

The `firefly/kernel` package provides LaraFly's product-agnostic error model. It has no Laravel or
external dependencies, so every other package (and your application) can throw and describe errors
consistently.

## The exception taxonomy

All framework exceptions extend `Firefly\Kernel\Exception\FireflyException`, which carries four pieces of
metadata beyond the message:

| Accessor | Meaning |
|---|---|
| `errorCode(): string` | A stable, machine-readable code (e.g. `RESOURCE_NOT_FOUND`). |
| `httpStatus(): int` | The HTTP status the web layer should emit. |
| `category(): ErrorCategory` | `Business` / `Validation` / `Security` / `Infrastructure` / `External` / `Framework` / `Plugin` / `Internal`. |
| `severity(): ErrorSeverity` | `Info` / `Warning` / `Error` / `Critical`. |

Typed subclasses fix these values. Selected examples:

| Exception | Code | Status | Category |
|---|---|---|---|
| `Business\ResourceNotFoundException` | `RESOURCE_NOT_FOUND` | 404 | Business |
| `Business\ConflictException` | `CONFLICT` | 409 | Business |
| `Business\PaymentRequiredException` | `PAYMENT_REQUIRED` | 402 | Business |
| `Business\ValidationException` | `VALIDATION_ERROR` | 422 | Validation |
| `Security\AuthenticationException` | `AUTHENTICATION_FAILED` | 401 | Security |
| `Security\AuthorizationException` | `ACCESS_DENIED` | 403 | Security |
| `Infrastructure\ServiceUnavailableException` | `SERVICE_UNAVAILABLE` | 503 | Infrastructure |
| `Infrastructure\RateLimitExceededException` | `RATE_LIMIT_EXCEEDED` | 429 | Infrastructure |
| `Infrastructure\DataAccessException` | `DATA_ACCESS_ERROR` | 500 | Infrastructure |
| `Infrastructure\DataIntegrityViolationException` | `DATA_INTEGRITY_VIOLATION` | 409 | Infrastructure |
| `Infrastructure\DuplicateKeyException` | `DUPLICATE_KEY` | 409 | Infrastructure |
| `Infrastructure\CannotAcquireLockException` | `LOCK_NOT_ACQUIRED` | 409 | Infrastructure |
| `Infrastructure\DeadlockLoserDataAccessException` | `DEADLOCK` | 409 | Infrastructure |
| `Infrastructure\OptimisticLockingFailureException` | `OPTIMISTIC_LOCKING_FAILURE` | 409 | Infrastructure |
| `Infrastructure\EmptyResultDataAccessException` | `EMPTY_RESULT` | 404 | Infrastructure |
| `Infrastructure\IncorrectResultSizeDataAccessException` | `INCORRECT_RESULT_SIZE` | 500 | Infrastructure |
| `Infrastructure\BadSqlGrammarException` | `BAD_SQL_GRAMMAR` | 500 | Infrastructure |
| `Infrastructure\QueryTimeoutException` | `QUERY_TIMEOUT` | 504 | Infrastructure |
| `Infrastructure\TransactionTimedOutException` | `TRANSACTION_TIMED_OUT` | 504 | Infrastructure |
| `Infrastructure\DataAccessResourceFailureException` | `DATASOURCE_UNAVAILABLE` | 503 | Infrastructure |
| `Infrastructure\TransientDataAccessResourceException` | `TRANSIENT_DATA_ACCESS_FAILURE` | 503 | Infrastructure |
| `External\ExternalServiceException` | `EXTERNAL_SERVICE_ERROR` | 502 | External |

The data-access rows are what `firefly/data` throws — its exception translation for the driver failures, and
`getById()`, `findOneByExample()`, the versioning trait and the transaction deadline for
`EmptyResultDataAccessException`, `IncorrectResultSizeDataAccessException`, `OptimisticLockingFailureException`
and `TransactionTimedOutException` respectively (see [Data & Repositories](data.md#exception-translation)). They
nest — `DuplicateKeyException` under `DataIntegrityViolationException`, `DeadlockLoserDataAccessException` under
`CannotAcquireLockException`, everything under `DataAccessException` **except** `TransactionTimedOutException`,
which sits under `Infrastructure\TimeoutException` like every other timeout (Spring's `TransactionException`
side, not its `DataAccessException` side) — so a handler catches at the granularity it needs: `catch
(DataAccessException $e)` does not see a transaction that ran past its deadline; `catch (TimeoutException $e)`
does. Their messages are fixed sentences; the driver's message, with the statement in it, stays on `previous`.

```php
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

throw new ResourceNotFoundException("Order {$id} not found");
```

## Extension members and a title of your own

RFC 9457 lets a problem document carry *extension members* beside the standard ones, and lets `title` be
the problem type's own phrase rather than the status's reason phrase. Both live on `FireflyException`, so
any exception in the taxonomy — or any subclass you write — can carry them without a renderer of its own:

```php
use Firefly\Kernel\Exception\Business\PaymentRequiredException;

throw (new PaymentRequiredException('The Team edition includes up to five workers.', 'EDITION_LIMIT'))
    ->withExtensions(['field' => 'workers', 'limit' => 5])
    ->withTitle('Your plan does not include this');
```

```json
{
  "status": 402,
  "title": "Your plan does not include this",
  "code": "EDITION_LIMIT",
  "category": "business",
  "severity": "warning",
  "detail": "The Team edition includes up to five workers.",
  "field": "workers",
  "limit": 5
}
```

`withExtensions()`/`withTitle()` mutate the instance and return it, so a throw site stays one expression and
no subclass has to widen its constructor; both are also constructor arguments on `FireflyException` itself
for a subclass that wants to fix them. An extension can never override a standard member: `ErrorResponse`
writes `status`, `title`, `code` and the rest *over* the extensions, so `['status' => 999]` is harmless.
The generated OpenAPI problem schema declares `additionalProperties: true` for the same reason.

## Field-level validation errors

`ValidationException` additionally carries `Firefly\Kernel\Error\FieldError` instances — the shape of Spring's
`FieldError`: the field path exactly as the client sent it (`shipTo.postcode`, `lines[1].sku`), a `message`
that describes the constraint, an optional application `code`, the `constraint` that failed by its attribute
name, and the `rejectedValue`:

```php
use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ValidationException;

throw new ValidationException('Validation failed', [
    new FieldError('email', 'must not be blank', constraint: 'NotBlank', rejectedValue: ''),
    new FieldError('age', 'must be >= 0', 'min', -1),
]);
```

Rendered inside the problem document's `errors` array as `field`, `message`, then `code`, `constraint` and
`rejectedValue` when present:

```json
"errors": [
  { "field": "email", "message": "must not be blank", "constraint": "NotBlank", "rejectedValue": "" },
  { "field": "age", "message": "must be >= 0", "code": "min", "rejectedValue": -1 }
]
```

`firefly/validation`'s `#[Valid]` produces these for every declared constraint — the constraint's own sentence
(`must not be blank`, `size must be between 1 and 50`, `must match "^[A-Z0-9]…"`) rather than Laravel's
humanised attribute (`The ship to.street field is required.`), unless `firefly.validation.messages` is
`laravel`; see [Validation § Field errors](validation.md#field-errors-shaped-like-springs). The HTML error
page renders the status, code and detail of a 422 and no field list.

## The RFC-7807 response

`Firefly\Kernel\Error\ErrorResponse` turns any `FireflyException` into a problem+json payload. The web
layer (a later package) renders it; here is the shape:

```php
use Firefly\Kernel\Error\ErrorResponse;

$response = ErrorResponse::fromException(
    $exception,
    instance: '/orders/42',
    traceId: $traceId,             // the id a person quotes: see "Web rendering" for which id that is
    correlationId: $correlationId, // always the correlation id, so the two are never conflated
);
$payload  = $response->toArray(); // omits null/empty optionals
```

```json
{
  "status": 404,
  "title": "Not Found",
  "code": "RESOURCE_NOT_FOUND",
  "category": "business",
  "severity": "warning",
  "detail": "Order 42 not found",
  "instance": "/orders/42",
  "traceId": "...",
  "correlationId": "..."
}
```

## Web rendering (M6)

`firefly/web` ([Web Layer](web.md)) is the concrete renderer this document promised: every
`FireflyException` thrown while handling a request is turned into an `application/problem+json` response
by `Firefly\Web\Exception\ProblemDetailsRenderer`, via `ErrorResponse::fromException(...)`, at the
exception's own `httpStatus()` — the shape is exactly the payload above, produced by the same
kernel-level `ErrorResponse` this page documents.

`Firefly\Web\Error\ProblemMapper` owns the rule for turning *any* throwable into that shape, in one place,
because the HTML page below needs the same answer and two copies of it would eventually tell a browser and a
client different things about one failure. It has **three** cases, and only the third is a disclosure:

| Throwable | Status | Whose message is it? |
|---|---|---|
| A `FireflyException` | its own `httpStatus()` | the application's, written **for** the client |
| An `HttpExceptionInterface` (the router's own 404, `abort(409, '…')`) | its real status | the author's, via `abort()` |
| Anything else | 500 `INTERNAL_ERROR` | **an accident**, and withheld — see below |

!!! danger "A generic throwable's message is not for the client"
    A `QueryException` stringifies the failing SQL *and its bindings*; a `TypeError` names an absolute path on
    the server; a `PDOException` names the host it could not reach. All three were copied verbatim into
    `detail` and published as problem+json. The problem document now has a gate of its **own**,
    `firefly.web.problem.disclose`, which defaults to `false` and follows *nothing* — not `app.debug`, not the
    HTML page's `trace`. For one release the JSON path shared `trace`, and that was the wrong gate for a machine
    surface: every local and compose environment sets `APP_DEBUG`, and a console fed by problem+json rendered
    a duplicate-key insert as the DSN, the tenant id and the full statement in a red banner while the HTML page
    beside it withheld everything. With the gate off an unhandled throwable answers
    `An unexpected error occurred. It has been logged; quote reference <traceId> if you report it.` and its
    real message stays on the exception, where the log has it beside the same id. When no settings object is
    bound at all — a JSON-only deployment that never constructed one — the default is the **safe** one; an
    absent gate must not mean an open one.

Two more things every problem document carries. **The ids — two of them, related and never conflated.**
**`traceId`** is the id a person quotes: the request's **W3C trace id** when tracing gave it a valid span,
and the correlation id when it did not — so pasting it into a trace search finds the request, which was the
one thing it could never do while the member named after a trace held a uuid no trace backend had heard of.
**`correlationId`** is always the correlation id, the one `CorrelationIdFilter` reads or mints at order
`-100` and stamps on every log line, and it is on the response as `X-Correlation-Id` exactly as before; a
request that arrived with no id is given one rather than left unreferenced. The trace id gets its **own**
response header (`X-Trace-Id`, see `firefly.web.trace-id.*` below) and is written only when there is one, so
the two ids never overwrite each other and a deployment with tracing off sees no new header at all. With
tracing off, `traceId` and `correlationId` are the same string — byte for byte what the document carried
before the trace id existed.

And the headers an `HttpExceptionInterface` carries are copied through: a **405** keeps its `Allow` header,
and is rendered with the reason phrase as its title, a sentence written for a person (`This address only accepts POST.`) in place of
the router's, and the permitted verbs in an `allowed` extension member. A 404 the router raised for a URL that
matches nothing says `There is nothing at this address.`; an `abort(404, '…')` message the author wrote is
kept verbatim. A 503 carries `Retry-After`, and PHP's own execution-time limit (`Maximum execution time of N
seconds exceeded`) is answered as `503 EXECUTION_TIME_EXCEEDED` rather than a 500 quoting the engine: the
request was not wrong, the server stopped it.

Before that generic rendering happens, LaraFly gives the application a chance to handle the exception
itself:

- **`#[ExceptionHandler(SomeException::class)]`** marks a method — on the throwing `#[RestController]`
  itself, or on a **`#[ControllerAdvice]`** bean — as the renderer for a specific exception class (or any
  of its subclasses).
- A **controller-local** handler (declared directly on the controller that threw) always beats a
  **global** `#[ControllerAdvice]` handler for the same exception.
- Within whichever scope wins, the **most-specific** matching handler by class hierarchy is chosen — a
  handler for a more-derived exception class outranks one for an ancestor class.
- A matched handler's return value is content-negotiated like any other controller return, but rendered
  at the **exception's** `httpStatus()` rather than the route's default status.
- If no handler matches at any scope, the exception propagates to the renderers described above — so an
  unhandled 404/422/500 comes back as `application/problem+json`, or as the LaraFly error page when the
  caller asked for HTML (see the next section), and never as an uncaught framework error page.

## Who gets JSON, and who gets a page

The same failure is rendered two ways, and the choice is not "is this a `FireflyException`". It used to be,
which meant a person clicking a stale link to `/orders/999999` in a browser was shown a raw JSON blob: the
exception taxonomy that makes LaraFly's errors consistent for clients was the very thing that made them
unreadable for people.

| The caller | What it gets |
|---|---|
| Named `text/html` (or `application/xhtml+xml`) in `Accept` | The HTML error page |
| Asked for JSON, or is an `XMLHttpRequest` | `application/problem+json` |
| Sent only a wildcard `Accept` — a bare `curl` | `application/problem+json` |
| Requested a path under `firefly.web.error-page.json-paths` | `application/problem+json`, whatever it asked for |

The rule is **the client NAMED text/html**, not `acceptsHtml()`. A bare `curl` sends `*/*`, which
`acceptsHtml()` answers true for, so keying off it would have turned every unadorned command-line request
against an API into an HTML page — a worse regression than the bug being fixed.

`json-paths` is the stronger statement and is checked **first**: the Accept header says who is asking, the
path says what the URL *is*. It defaults to `api/*`, because a developer opening an API URL in a browser
wants the payload their client will receive, not a styled page telling them the endpoint renders HTML.

## The HTML error page

`firefly/web` ships a page in the same visual language as the welcome page and the admin dashboard, showing
the status, the reason, the stable error `code` — the same one the problem document carries, so a support
ticket quoting it finds the same code in the log — and, when permitted, the exception, its `previous` chain,
the source around the throwing line, and the stack trace with **your** frames separated from your
dependencies'.

```php
// config/firefly.php
'web' => [
    'error-page' => [
        'enabled' => true,                  // false falls back to Laravel's own page
        'trace' => env('APP_DEBUG', false), // the disclosure gate; follows app.debug
        'title' => env('APP_NAME', 'LaraFly'),
        'excerpt-lines' => 7,               // source lines around the throw, clamped 0-40
        'json-paths' => 'api/*',
        'views' => ['404' => 'errors.not-found', 'default' => 'errors.generic'],
    ],
],
```

The reference both surfaces publish has two keys of its own:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `firefly.web.trace-id.enabled` | bool | `true` | Whether the W3C trace id is what `traceId`, the page's **Reference** row and the echoed header publish. `false` puts the correlation id back in all three — the pre-trace behaviour — and turns the echo off. The trace id is still read for logs and the HTTP-exchange row; only publishing it stops. |
| `firefly.web.trace-id.header` | string | `X-Trace-Id` | The response header the trace id is echoed on, beside `X-Correlation-Id` and never in place of it. `''` disables the echo and leaves the document and the page untouched. It is deliberately **not** W3C `traceresponse`, which LaraFly does not implement. |

**`trace` is enforced where the data is gathered, not where it is printed.** With it off the framework never
walks the stack, never opens a source file and never copies the exception message — so there is nothing
assembled for a template mistake to leak. Production shows the status, the reason, the code and the request's
**reference** — the same id the problem document publishes as `traceId`, which is the W3C trace id when the
request had a valid span and the correlation id when it did not, and the same value the response echoes on
`X-Trace-Id` — so the 500 page reads "quote reference `<id>` if you report it" and the id a person
screenshots is the one a trace search resolves. When the two ids differ the page carries a **Correlation**
fact row beside the Reference one, holding the `X-Correlation-Id` value; when they are the same string the
row is omitted, because two rows repeating one value teach a reader the ids are interchangeable. That is
enough to quote into a ticket and grep in a log, and nothing that names a class, a file or a row. The page's own
advice about *how* to turn traces on is suppressed outside non-production environments too, because naming
the framework and a config key to an anonymous visitor is a free hint about your stack.

**Overriding it.** `views` hands a status — or `default` — to your own Blade view. The view receives the same
`$error` report the built-in page gets, so it is bound by the same `trace` gate and cannot print a stack
trace the settings withheld. A view that **throws** falls back to the built-in page rather than propagating:
this renders while the application is already failing, and an override is application code (a renamed
layout, a component querying the database that is down) — a white screen at that moment is the worst
possible outcome.

**It is not a Blade view itself.** The built-in page is assembled as a string with no container lookups, no
view factory and no network font, because the failure being explained may *be* the view layer. String
building is not the elegant choice; it is the one that still works when nothing else does.

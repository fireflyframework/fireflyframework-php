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
| `Business\ValidationException` | `VALIDATION_ERROR` | 422 | Validation |
| `Security\AuthenticationException` | `AUTHENTICATION_FAILED` | 401 | Security |
| `Security\AuthorizationException` | `ACCESS_DENIED` | 403 | Security |
| `Infrastructure\ServiceUnavailableException` | `SERVICE_UNAVAILABLE` | 503 | Infrastructure |
| `Infrastructure\RateLimitExceededException` | `RATE_LIMIT_EXCEEDED` | 429 | Infrastructure |
| `External\ExternalServiceException` | `EXTERNAL_SERVICE_ERROR` | 502 | External |

```php
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

throw new ResourceNotFoundException("Order {$id} not found");
```

## Field-level validation errors

`ValidationException` additionally carries `Firefly\Kernel\Error\FieldError` instances:

```php
use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ValidationException;

throw new ValidationException('Validation failed', [
    new FieldError('email', 'is required'),
    new FieldError('age', 'must be >= 0', 'min', -1),
]);
```

## The RFC-7807 response

`Firefly\Kernel\Error\ErrorResponse` turns any `FireflyException` into a problem+json payload. The web
layer (a later package) renders it; here is the shape:

```php
use Firefly\Kernel\Error\ErrorResponse;

$response = ErrorResponse::fromException($exception, instance: '/orders/42', traceId: $traceId);
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
  "traceId": "..."
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
    `detail` and published as problem+json — in production, with no `app.debug` gate anywhere on that path,
    while the HTML page beside it withheld everything. Both renderings are now gated by the same switch,
    `firefly.web.error-page.trace`, which follows `app.debug`: with it off an unhandled throwable answers
    `An unexpected error occurred.` and its real message stays on the exception, where the log has it. When
    no settings object is bound at all — a JSON-only deployment that never constructed one — the default is
    the **safe** one; an absent gate must not mean an open one.

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

**`trace` is enforced where the data is gathered, not where it is printed.** With it off the framework never
walks the stack, never opens a source file and never copies the exception message — so there is nothing
assembled for a template mistake to leak. Production shows the status, the reason and the code: enough to
quote into a ticket and grep in a log, and nothing that names a class, a file or a row. The page's own
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

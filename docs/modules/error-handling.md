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

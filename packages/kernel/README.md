# firefly/kernel

The zero-dependency foundation of LaraFly (the Firefly Framework for PHP):

- `Firefly\Kernel\Lifecycle` — the `start()`/`stop()` contract every infrastructure adapter implements.
- `Firefly\Kernel\Exception\*` — a product-agnostic, typed exception taxonomy carrying error code,
  HTTP status, category, and severity.
- `Firefly\Kernel\Error\*` — an RFC-7807-inspired `ErrorResponse` model.

No Laravel, no external dependencies. Apache-2.0 © Firefly Software Solutions Inc.

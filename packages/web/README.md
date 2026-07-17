# firefly/web

LaraFly's HTTP layer: `#[RestController]` classes (ordinary `#[Component]` stereotypes, so constructor DI
is free) compiled to a `RouteManifest`, parameter binding with `#[PathVariable]`/`#[QueryParam]`/
`#[RequestBody]`, `#[Valid]` interception via Bean Validation, JSON-native content negotiation over a
`MessageConverter` seam, RFC-7807 rendering with `#[ExceptionHandler]`/`#[ControllerAdvice]`, and an
ordered `WebFilter` chain bridged onto Laravel's middleware — Spring Web MVC's shape, native to Laravel.

Apache-2.0 © Firefly Software Solutions Inc.

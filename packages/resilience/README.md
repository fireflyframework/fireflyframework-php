# firefly/resilience

LaraFly's resilience layer: six programmatic patterns — `Retry`, `CircuitBreaker`, `RateLimiter`,
`Fallback`, `Bulkhead`, `TimeLimiter` — each a `call(callable): mixed` object. CircuitBreaker,
RateLimiter and Bulkhead are **cache-backed** (state survives across share-nothing PHP-FPM requests via
Laravel's atomic `Cache`), built once from `firefly.resilience.*` by a config-driven `ResilienceRegistry`.
Reflection-free and config-driven; attribute-driven interception (`#[Retry]` …) lands in SP-5 with AOP.

Apache-2.0 © Firefly Software Solutions Inc.

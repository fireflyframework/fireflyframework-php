# firefly/resilience

LaraFly's resilience layer: six programmatic patterns — `Retry`, `CircuitBreaker`, `RateLimiter`,
`Fallback`, `Bulkhead`, `TimeLimiter` — each a `call(callable): mixed` object. CircuitBreaker,
RateLimiter and Bulkhead are **cache-backed** (state survives across share-nothing PHP-FPM requests via
Laravel's atomic `Cache`), built once from `firefly.resilience.*` by a config-driven `ResilienceRegistry`.
Reflection-free and config-driven; the six also apply as attributes (`#[Retry]`, `#[CircuitBreaker]`,
`#[RateLimiter]`, `#[Bulkhead]`, `#[TimeLimiter]`, `#[Fallback]`) through `ResilienceAdviceSource` on the
method-interceptor chain shared with `#[Transactional]`, at order 200 — inside method security, outside the
transaction — gated by `firefly.resilience.method.enabled`.

Apache-2.0 © Firefly Software Solutions Inc.

# Web Filters

`firefly/web` bridges a Spring-style ordered filter chain onto Laravel's own middleware pipeline, so a
`WebFilter` bean runs inside the exact same HTTP-kernel pipeline as CORS, CSRF, and secure-headers
middleware — no bespoke request pipeline of its own.

## The `WebFilter` contract

`Firefly\Web\Filter\WebFilter` is shaped exactly like Laravel middleware:

```php
interface WebFilter
{
    public function handle(Request $request, Closure $next): mixed;

    public function shouldNotFilter(Request $request): bool;
}
```

so a `WebFilter` bean *is* directly registerable as global middleware — no adapter layer translates
between the two. `Firefly\Web\Filter\OncePerRequestFilter` is the usual base class: it implements
`handle()` to short-circuit to `$next` whenever `shouldNotFilter()` returns true, and asks concrete
subclasses to implement `doFilter()` plus optional `urls()`/`excludes()` glob lists (Laravel's
`Str::is()` patterns) to scope which paths the filter applies to:

```php
final class AuditFilter extends OncePerRequestFilter
{
    protected function doFilter(Request $request, Closure $next): mixed
    {
        // ... before-request work ...
        $response = $next($request);
        // ... after-response work ...
        return $response;
    }

    protected function urls(): array
    {
        return ['/api/*'];
    }
}
```

A `WebFilter` is discovered as an ordinary `#[Component]` bean (typically `#[Order(n)]`-annotated), so
it participates in constructor DI like any other bean.

## `#[Order]`-driven `FilterChainRegistrar`

At boot (phase `WiringPasses`), `FilterChainRegistrar` collects every bean definition assignable to
`WebFilter`, sorts them by `#[Order]` — **read from the compiled bean definition's descriptor, never from
a resolved instance** (the same invariant the M4 boot engine holds elsewhere) — breaking order ties by
FQCN for determinism, and pushes the ordered list onto Laravel's global middleware stack via
`Illuminate\Foundation\Http\Kernel::pushMiddleware()`. Because ordering is decided from static metadata
before any filter is instantiated, the chain's shape never depends on container-resolution order.

## The two framework filters

Two `WebFilter`s ship with the package itself and are always prepended, at fixed negative orders, ahead
of any application filter:

| Filter | Order | Behaviour |
|---|---|---|
| `RequestContextFilter` | `-200` | Seeds a fresh UUID into Laravel's `Context` facade as `firefly.request_id`, scoped to the request. |
| `CorrelationIdFilter` | `-100` | Reads an inbound `X-Correlation-Id` header (minting one if absent), exposes it via `Context` as `firefly.correlation_id`, and echoes it back on the response header. |

Metrics and tracing filters are **not** part of this release — only the `WebFilter` seam and these two
framework filters ship in M6; metrics/tracing filters are tracked for **M12**.

## CORS / CSRF / secure-headers: reuse, not bespoke code

CORS, CSRF, and security-header hardening are deliberately **not** reimplemented as `WebFilter`s — they
are Laravel's own, well-tested middleware, wired through the exact same global-middleware surface
`FilterChainRegistrar` pushes onto:

- **CORS** — Laravel's `Illuminate\Http\Middleware\HandleCors`, configured via `config/cors.php` as usual.
- **CSRF** — Laravel's `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` for any stateful,
  cookie-authenticated routes.
- **Secure headers** — optionally, the `bepsvpt/secure-headers` package, which registers its own
  middleware the same way.

No LaraFly code sits between a request and these three concerns; an application composes them exactly as
it would in a plain Laravel project, and they compose with the ordered `WebFilter` chain because both
live on the same middleware stack.

---

Apache-2.0 © Firefly Software Solutions Inc.

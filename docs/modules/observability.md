# Observability

`firefly/observability` is LaraFly's metrics core — the Micrometer/Prometheus-client analogue. It ships a first-party,
pure-PHP `MeterRegistry` (no `ext-prometheus`, no OpenTelemetry library), a Prometheus 0.0.4 text exposition and a
Micrometer-JSON `/metrics` endpoint (both mounted on `firefly/actuator`), HTTP auto-instrumentation, the real
`CqrsMetrics` recorder that drops into the M10 seam, a circuit-breaker gauge, process metrics, correlation-id log
enrichment, and a `Tracer` port (`NoOpTracer` shipped; the OpenTelemetry adapter lands at SP-7). Gated on one config
flag, secure-by-default-on, zero boot reflection.

## Metrics model

- `MeterRegistry` — the read-facing factory port: `counter(name, tags)`, `timer(name, tags)`, `gauge(name, tags,
  callable $supplier)`, `meters(): list<Meter>`. Registration is **idempotent** per `type|name|sorted-tags` — repeated
  calls with the same identity return the same instance.
- `MetricsRecorder` — the narrow write-facing port instrumentation actually depends on (`increment()`, `record()`,
  `setGauge()`), so callers never need the full registry.
- `SimpleMeterRegistry` — the shipped in-memory implementation of **both** ports, and the default. `Counter`
  (monotonic), `Gauge` (pull-based, backed by a `callable(): float` supplier — sampled at *read* time, not write
  time), `Timer` (count + total-seconds, exposed as a Prometheus summary — **no** histogram buckets/percentiles yet).
- `CacheMeterRegistry` — the cross-process implementation of both ports, bound instead when
  `firefly.observability.metrics.store` names a cache store. See [Surviving the request](#surviving-the-request)
  below.
- A metric **name** has exactly one type, globally: registering the same name under a different `MeterType` (e.g.
  `counter('foo')` then `gauge('foo', ...)`) throws `InvalidArgumentException` — Prometheus scopes one `# TYPE` line
  per name, so a silent type conflict would emit invalid exposition.

## Surviving the request

`SimpleMeterRegistry` keeps every meter in process memory. That is correct for a long-lived worker (Octane,
RoadRunner) and wrong for PHP's usual deployment: under PHP-FPM each request is a fresh process, so by the time a
scrape reaches `/actuator/metrics` or `/actuator/prometheus`, the only meters in memory are the ones that scrape's
own request recorded. The endpoints were effectively empty in production — and the numbers they *did* show were a
single request's, which is worse than empty, because it reads as data.

Naming a cache store swaps in `CacheMeterRegistry`, which writes through to that store:

- `increment()` and `record()` use the store's **atomic increment**, so concurrent workers cannot lose writes on a
  driver that supports it (redis, memcached, apc, dynamodb). Durations accumulate in integer **microseconds**,
  because `increment()` is integer-only and a float read-modify-write would drop samples under concurrency.
- `setGauge()` is a plain `put()`: a gauge is a snapshot, so last-writer-wins is the correct semantic.
- `meters()` rehydrates `Counter`/`Timer`/`Gauge` from a single index of every meter identity ever written, so it
  costs one read rather than a key scan — which not every cache driver supports.
- Tag order never splits a meter in two; identities sort their tags.

```php
// config/firefly.php
'observability' => [
    'metrics' => [
        'store' => env('FIREFLY_METRICS_STORE', ''),   // '' = in-process SimpleMeterRegistry
        'ttl' => 0,                                     // seconds; 0 = no expiry
    ],
],
```

It is **opt-in** rather than the default on purpose: a metrics registry that silently starts writing to whatever
cache an application happens to have configured is a surprise, and on the `array` driver it would be no better
than memory anyway.

The documented boundary: the factory methods (`counter()`/`timer()`/`gauge()`) still hand back the **in-process**
meters, and mutating one of those directly stays process-local. Everything the framework itself records goes
through the `MetricsRecorder` methods, which are the durable path.

## Exposition

- `/actuator/prometheus` (`PrometheusEndpoint`) — pure-PHP Prometheus text-exposition format 0.0.4. Names/labels are
  sanitised to the format's grammar; label values and HELP text are escaped; floats render via `number_format()`
  (never `sprintf('%f')`) so a comma-decimal locale (e.g. `de_DE`) can never leak an unscrapeable `','` into the
  output.
- `/actuator/metrics` (+ `/actuator/metrics/{name}`) (`MetricsEndpoint`) — the Micrometer-JSON shape: no sub-path →
  `{"names": [...]}` (sorted, unique); a metric name → its measurements (`COUNT`/`TOTAL_TIME`/`VALUE`) + available
  tags, or `404` if unknown.
- Both endpoints are `#[Component]`s of `firefly/actuator`'s `ActuatorEndpoint` contract, reachable at
  `{base-path}/{id}` once actuator's exposure list includes them (see `docs/modules/actuator.md`) — observability
  ships them, actuator mounts them.

## Auto-instrumentation

- `MetricsFilter` — a `#[Component]` `WebFilter` (discovered by `firefly/web`'s `FilterChainRegistrar`, no web edit)
  timing every HTTP request as `http_server_requests_seconds{method,uri,status,outcome,exception}`. `#[Order(-100)]`
  makes it the outermost discovered filter, so it wraps the whole inner chain. The `uri` tag is the matched route's
  **template** (`/orders/{id}`), never the raw path (`/orders/42`) — bounded label cardinality. On a thrown request
  it records `outcome=SERVER_ERROR` + the exception's short class name, then **rethrows** (no swallow) so
  `ProblemDetailsRenderer` still renders the error.
- `MeterBindingsPass` — a `BootPass` that, once a `MeterRegistry` is bound, registers pull-based gauges:
  `process_resident_memory_bytes` / `php_memory_peak_bytes`, and one `resilience_circuit_breaker_state{name}` gauge
  per configured `firefly.resilience.circuit-breaker.*` instance (`closed=0` / `open=1` / `half_open=2`, read live —
  a fresh scrape always reflects the breaker's *current* state, not a snapshot from boot time). It only touches
  `firefly/resilience` when that package is actually installed (a soft `bound()` guard at runtime, even though the
  Deptrac edge exists).
- `CorrelationIdLogProcessor` — pushed onto the default log channel's real Monolog logger (unwrapped via
  `Illuminate\Log\Logger::getLogger()`, the same idiom Laravel's own `ContextLogProcessor` uses — `LogManager`'s
  `pushProcessor` only exists via `__call()` magic, which `method_exists()` cannot see). Every log record gets the
  request's correlation id (Laravel `Context`) in `extra`, tying logs to the same id CQRS's `CorrelationContext`
  stamps on commands/queries/events.

## The CqrsMetrics drop-in (the M10 seam)

`packages/cqrs/src/Metrics/CqrsMetrics.php` was built in M10 as a deliberate extension point: `CqrsAutoConfiguration`
binds a `NoOpCqrsMetrics` behind `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, with a docblock promising a real
recorder would drop in later as a bean swap. `firefly/observability` is that drop-in:

```
MeterRegistryCqrsMetrics implements CqrsMetrics — records each command/query as a timer:
  cqrs_commands_seconds{type, outcome}
  cqrs_queries_seconds{type, outcome}
```

The precedence trick: `ObservabilityAutoConfiguration` is `#[Configuration] #[Order(500)]` — **deliberately below**
`CqrsAutoConfiguration`'s `#[Order(1000)]` (the same mechanism `firefly/security`'s `SecurityAutoConfiguration` uses
against the same seam). The incremental condition pass evaluates auto-configs low-`#[Order]`-first and registers
survivors before the next config is evaluated, so `cqrsMetrics()` registers **first** — Cqrs's own
`#[ConditionalOnMissingBean(CqrsMetrics::class)]` then sees a bean already bound and backs off. No code in
`firefly/cqrs` changes; the win is pure auto-configuration ordering.

## Gating — property, not bean presence

Every observability bean/endpoint/filter gates on the **same** config property,
`#[ConditionalOnProperty('firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]` — the
`meterRegistry()` bean, `cqrsMetrics()`, `PrometheusEndpoint`, `MetricsEndpoint`, and `MetricsFilter` all read it.

This is deliberate, not incidental: a `#[ConditionalOnBean(MeterRegistry::class)]` on the endpoints/filter/
`cqrsMetrics()` would evaluate at condition-pass time **before** `ObservabilityAutoConfiguration`'s own `#[Order(500)]`
registers `MeterRegistry` — wrongly dropping every downstream bean/component even when metrics are enabled, purely
because of evaluation order. Gating everything on the identical property instead makes survival order-independent:
flip the one flag, and the `MeterRegistry` bean, the CqrsMetrics winner, and every consumer either **all** survive or
**all** back off together. Disabled: no `MeterRegistry`, the M10 `NoOpCqrsMetrics` stays bound, and
`/actuator/prometheus` + `/actuator/metrics` are unreachable (`404` — never registered, not merely unauthorized).

## Tracing

`Tracer { trace(string $name, callable $callback): mixed }` — a minimal span port. M12 ships only `NoOpTracer`
(runs the callback with no span); instrumentation is written against the interface today so an OpenTelemetry-backed
adapter can drop in at SP-7 with zero call-site changes — the same "port now, adapter later" shape as the CqrsMetrics
seam above.

## HTTP exchanges and the process endpoint

`/actuator/httpexchanges` serves the last N requests this application answered, newest first, and
`/actuator/process` serves the live process numbers (pid, uptime, PHP version/SAPI, memory, OPcache) beside the
request counter the same recorder already keeps. Neither is in the secure-by-default exposure list
(`health,info`), so reaching either over HTTP means naming it in
`firefly.management.endpoints.web.exposure.include`.

Recording is done by `HttpExchangeFilter` — a `#[Component]` `WebFilter` discovered by web's
`FilterChainRegistrar`, `#[Order(-100)]`, `#[Lazy]`, gated on its **own**
`firefly.observability.httpexchanges.enabled` rather than on the metrics flag. Each row carries
`timestamp`/`method`/`uri`/`status`/`durationMs`/`correlationId`; `uri` is the **route template** where a route
matched, and otherwise the raw path with the query string dropped, capped at 256 characters. **No request or
response body is ever retained, and there is no flag to enable one.** Headers are off by default; switching
`include-headers` on adds a `requestHeaders` object whose credential-bearing entries (`authorization`, `cookie`,
`proxy-authorization`, anything matching `password|secret|token|key|credential|passwd|authenticate`) are replaced
with `******` by `HeaderMasker`.

`HttpExchangeRecorder` is a port with the same two implementations, and the same reason for them, as
`MeterRegistry`: `InMemoryHttpExchangeRecorder` (default, correct only on a long-lived worker) and
`CacheHttpExchangeRecorder`. Under PHP-FPM the in-memory buffer is not merely stale but always **empty** — each
request is a fresh process, and the request rendering the endpoint has not been recorded yet because the filter
records on the way out. The payload therefore reports `storage` (`memory` or `cache:<store>`), `processLocal`,
`recording`, `capacity`, `recorded` (monotonic, so `recorded - count` is what the ring has evicted) and `count`,
so an empty list can be told apart from a broken one. `?limit=N` trims the list; a malformed limit is ignored
rather than answered with a `400`.

## Configuration (`firefly.observability.*`, kebab-case)

| Key | Default | Meaning |
|---|---|---|
| `firefly.observability.metrics.enabled` | `true` | Master gate. Binds `MeterRegistry`/`MetricsRecorder`/`PrometheusTextFormat`/the real `CqrsMetrics`, and survives on the endpoints + `MetricsFilter`. Disabled → `NoOpMetricsRecorder`, the M10 `NoOpCqrsMetrics` stays bound, no `MeterRegistry`, `/prometheus`+`/metrics` unmounted. |
| `firefly.observability.metrics.store` | `''` | Names a **cache store**. Empty (or no `cache` binding) → the in-process `SimpleMeterRegistry`; a store name → `CacheMeterRegistry` over `cache()->store($name)`, keyed under `firefly:metrics:`. |
| `firefly.observability.metrics.ttl` | `0` | Expiry in seconds for each cache-backed meter. `0` or less means no expiry. Only consulted when `store` is set. |
| `firefly.observability.httpexchanges.enabled` | `true` | Gates `HttpExchangeFilter` — i.e. whether anything is recorded. Compared as the literal string `true` by `#[ConditionalOnProperty]`, so `1`/`on`/`yes` count as OFF. The endpoints stay mounted either way and report `"recording": false`. Independent of the metrics gate. |
| `firefly.observability.httpexchanges.capacity` | `100` | Ring size, clamped to `[1, 10000]`. |
| `firefly.observability.httpexchanges.store` | `''` | Names a **cache store**. Empty (or no `cache` binding) → the process-local `InMemoryHttpExchangeRecorder`; a store name → `CacheHttpExchangeRecorder` over `cache()->store($name)`, keyed under `firefly:httpexchanges:`, which is what makes the buffer non-empty under PHP-FPM. |
| `firefly.observability.httpexchanges.ttl` | `0` | Expiry in seconds for each cache-backed row. `0` or less means no expiry. Only consulted when `store` is set. |
| `firefly.observability.httpexchanges.include-headers` | `false` | Adds masked request headers to each row. Bodies are never recorded, with or without this. |
| `firefly.observability.httpexchanges.exclude` | `[<management base path>, <management base path>/*]` | Glob patterns whose requests are not recorded. The default keeps a polling dashboard from evicting real traffic from its own ring; setting it **replaces** the default rather than adding to it. |
| `firefly.resilience.circuit-breaker.*` | _(unset)_ | Read by `MeterBindingsPass` (not owned by this package) — one named instance here gets one `resilience_circuit_breaker_state{name}` gauge. |

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/observability`) |
|---|---|---|
| Metrics | no first-party equivalent (typically a 3rd-party Prometheus package + `ext-prometheus`) | first-party pure-PHP `MeterRegistry` + Prometheus 0.0.4 text, no extension |
| HTTP timing | manual middleware | `MetricsFilter`, auto-discovered, templated-URI tags |
| CQRS metrics | n/a (no first-party CQRS) | `MeterRegistryCqrsMetrics` — a config-only bean-precedence swap over the M10 `NoOpCqrsMetrics` |
| Correlation | `Context` alone | `CorrelationIdLogProcessor` ties every log line to the same id CQRS stamps |
| Tracing | none first-party | `Tracer` port + `NoOpTracer`; OTel adapter deferred to SP-7 |

## Known-latent

- **OTLP push / an OpenTelemetry `Tracer` adapter** — SP-7. Today's `NoOpTracer` is a real, callable port with zero
  span overhead; nothing downstream needs to change when the adapter lands.
- **Histogram buckets / percentiles** — `Timer` only exposes as a Prometheus *summary* (`_count`/`_sum`); no
  `histogram_quantile`-friendly buckets yet.
- **Multiprocess aggregation is opt-in, and partial.** `firefly.observability.metrics.store` gives counters,
  timers and set-gauges cross-process totals through the cache (see [Surviving the
  request](#surviving-the-request)); without it, `SimpleMeterRegistry` exposes only the calling process's own
  meters. Two limits remain even with a store: the `counter()`/`timer()`/`gauge()` factory objects stay
  process-local, and a pull-based gauge registered by `MeterBindingsPass` is sampled in whichever process serves
  the scrape (which is the correct semantic for `php_memory_peak_bytes`, and the only possible one for a live
  circuit-breaker read).
- **A second Octane management-port listener** — deferred alongside `firefly/actuator`'s own known-latent (no second
  management port; doesn't fit PHP-FPM). An SP-7 option for Octane deployments.

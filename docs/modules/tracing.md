# Tracing

`firefly/observability` carries a Spring Boot / OpenTelemetry-shaped tracing port — `Tracer`, `Span`,
`SpanKind`, `SpanStatus`, `SpanContext` — with `NoOpTracer` as the shipped default and an OpenTelemetry
adapter that binds itself when the SDK is installed and `firefly.observability.tracing.enabled` is on. With it
on, every request gets a SERVER span continued from an inbound W3C `traceparent`, every Laravel `Http` client
call gets a CLIENT span and sends `traceparent`, every command and query gets an INTERNAL span, and every
event carries `traceparent` in its envelope with a PRODUCER span on publish and a CONSUMER span on delivery —
whichever transport carried it. The trace and span ids reach Laravel `Context` (`firefly.trace_id`,
`firefly.span_id`), every log line (see [Logging](logging.md)), and `/actuator/httpexchanges`.

## The port

```php
interface Tracer
{
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span;
    public function currentSpan(): ?Span;
    public function trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array $attributes = []): mixed;
}
```

`startSpan()` starts a span **and makes it current** until `end()`. A span started with no parent is a child of
the current one; `SpanContext::invalid()` as the parent starts a new root; a valid remote parent (what
`W3CTraceContextPropagator::extract()` returns) continues that trace. `trace()` is the convenience: start,
run the callback with the span, record a throwable as an `ERROR` status plus an `exception` event, rethrow,
end. `Span` is fluent (`updateName`, `setAttribute`, `setAttributes`, `addEvent`, `setStatus`,
`recordException`) and `end()` is idempotent, so a `finally` can call it unconditionally. A span whose end is
deferred past the frame that started it — the CLIENT span of an `Http` call ends in the promise's `then()` —
calls `deactivate()` as soon as its synchronous part is over: it stops being current (so what starts next is a
sibling, not a child) while staying open for the attributes, status and `end()` that arrive with the result.
That is what keeps every request of an `Http::pool()` a sibling under the span that issued it.

```php
final class ShipOrderHandler
{
    public function __construct(private readonly Tracer $tracer) {}

    public function handle(ShipOrder $command): void
    {
        $this->tracer->trace('carrier.book', function (Span $span) use ($command): void {
            $span->setAttribute('order.id', $command->orderId);
            $this->carrier->book($command->orderId);   // an Http:: call inside gets its own CLIENT span
        }, SpanKind::Internal);
    }
}
```

`NoOpTracer` hands out non-recording spans with an invalid context and `currentSpan()` of `null`, so every
instrumentation site can test `$span->context()->isValid()` before publishing ids — and does.

## Propagation

![One traceparent entering at the TracingFilter and flowing through Laravel Context, the CQRS bus, the EDA envelope and the outbound Http client, landing on every log line, on /actuator/httpexchanges and on the dashboard](../assets/diagrams/tracing-propagation.svg)

`Firefly\Observability\Tracing\W3CTraceContextPropagator` speaks [W3C Trace Context](https://www.w3.org/TR/trace-context/)
over a plain header map, with no SDK involved: `extract(array $carrier): ?SpanContext` follows the receiver
rules (version `ff` and all-zero ids rejected, a future version read for its first four fields, names matched
case-insensitively, list or string values) and `inject(SpanContext): array<string,string>` writes
`traceparent` and, when there is one, `tracestate` — nothing at all for an invalid context.

| Boundary | Where | Span | What is carried |
|---|---|---|---|
| Inbound HTTP | `TracingFilter` — a `#[Component] WebFilter` at `#[Order(-110)]`, the outermost discovered filter (right after `RequestContextFilter` and `CorrelationIdFilter`, wrapping `HttpExchangeFilter`/`MetricsFilter`) | `SERVER`, named `GET /orders/{id}` once the router has matched; `http.request.method`, `url.path`, `url.scheme`, `server.address`, `http.route`, `http.response.status_code`, `firefly.correlation_id`; `ERROR` on 5xx or a throw | `traceparent`/`tracestate` read from the request; ids published to `Context` and to `Request::$attributes` (where `HttpExchangeFilter` reads the `traceId` for the exchange row) |
| Outbound HTTP | `HttpClientTracingMiddleware`, a Guzzle middleware `HttpClientTracingPass` installs on the `Http` factory at boot (`Http::globalMiddleware()`) | `CLIENT`, named by the method; `http.request.method`, `url.scheme`, `server.address`, `server.port`, `url.path` (never `url.full` — the query string is where tokens live), `http.response.status_code`; `ERROR` at ≥ 400 or on a rejection | `traceparent`/`tracestate` set on the PSR-7 request; works under `Http::fake()` (global middleware is outermost) |
| CQRS | `CqrsTracing` seam in `firefly/cqrs` (`NoOpCqrsTracing` default), filled by `TracerCqrsTracing` | `INTERNAL`, named by the message's short class; `firefly.cqrs.kind`, `firefly.cqrs.message` | nothing to carry — in-process; the span nests under whatever is current |
| EDA | `EdaTracing` seam in `firefly/eda` (`NoOpEdaTracing` default), filled by `TracerEdaTracing`; called by `InMemoryEventBus`, `QueueEventBus` (publish and the worker-side `deliver()`) and `SubscriberRegistrySink` (every broker consumer) | `PRODUCER` `publish <destination>` / `CONSUMER` `process <destination>`; `messaging.system=firefly-eda`, `messaging.destination.name`, `messaging.operation.type`, `messaging.message.id`, `firefly.eda.event_type` | `traceparent`/`tracestate` in the envelope headers, beside `x-correlation-id` |

Both seams are the `CqrsMetrics` shape: an interface in the owning package with a no-op default behind
`#[ConditionalOnMissingBean]`, and observability's `#[Order(500)]` auto-configuration registering the real one
first. Neither `firefly/cqrs` nor `firefly/eda` depends on observability.

## The OpenTelemetry adapter

```bash
composer require open-telemetry/sdk            # the API comes with it
composer require open-telemetry/exporter-otlp  # for exporter=otlp
```

`OpenTelemetryAutoConfiguration` (`#[Configuration] #[Order(400)] #[ConditionalOnClass(OpenTelemetry\SDK\Trace\TracerProvider)]
#[ConditionalOnProperty(firefly.observability.tracing.enabled)]`) binds an SDK `TracerProviderInterface` and
the `Tracer` over it, ahead of `ObservabilityAutoConfiguration`'s `#[Order(500)]` NoOp — which backs off through
`#[ConditionalOnMissingBean(Tracer::class)]`. When either condition fails, `/actuator/conditions` says which,
and the NoOp stays: every instrumentation site costs a few method calls and publishes nothing.

- **Exporter** (`tracing.exporter`): `none` (spans recorded for ids and propagation, exported nowhere — the
  default), `console` (one JSON document per span on stdout), `otlp`. A `SpanExporterInterface` bean the
  application binds wins over the key — the seam a Testbench suite uses with the SDK's `InMemoryExporter`.
- **OTLP** (`tracing.otlp.*`): `endpoint` is the collector's base URL (`/v1/traces` is appended for the HTTP
  protocols, as the spec does for `OTEL_EXPORTER_OTLP_ENDPOINT`); `protocol` is `http/protobuf` (default),
  `http/json` or `grpc` (needs `open-telemetry/transport-grpc` + `ext-grpc`, refused with the package name
  otherwise); `headers` is `name=value,name2=value2` (the `OTEL_EXPORTER_OTLP_HEADERS` shape, parsed and
  percent-decoded by the SDK's own parser, so a vendor's documented `Authorization=Basic%20<b64>` works
  verbatim and a pair without `=` refuses to boot) or a map, whose values are taken as written. OTLP spans are
  batched and flushed by `Application::terminating()` — the end of a PHP-FPM request, Octane's per-request
  terminate.
- **Sampler** (`tracing.sampler.type` / `ratio`): `always_on`, `always_off` or `ratio`, always wrapped in
  `ParentBased` so an inbound `traceparent`'s sampled flag wins.
- **Resource**: the SDK's defaults plus `service.name` (`tracing.service-name`, else `app.name`),
  `deployment.environment.name` (`app.env`) and `tracing.resource-attributes`.
- **Fibers**: the API's context storage is fiber-bound — one scope stack per `Fiber` — and a fiber that reads
  its context while that stack is empty raises `E_USER_WARNING` (`must attach initial fiber context
  manually`), an `ErrorException` under Laravel's handler. Before every `startSpan()`/`currentSpan()` read,
  `OpenTelemetryTracer` checks the current fiber and, when its stack is empty, attaches the root context as
  the fiber's floor — laid once per fiber and never detached — so a request handled inside a fiber (the
  browser suite's in-process server, an Amp or ReactPHP application server) gets a trace of its own instead
  of a 500. A scope the application or the FFI fiber observer (`OTEL_PHP_FIBERS_ENABLED`) already put in the
  fiber is nested under, never shadowed, and the fiber is checked again on later reads: when that scope is
  detached (a hook that wrapped one call, a worker fiber between two units of work) the floor is laid then.

## Configuration (`firefly.observability.tracing.*`, kebab-case)

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Master gate. Compared as the literal `true` by `#[ConditionalOnProperty]`. |
| `exporter` | `none` | `none` \| `console` \| `otlp`. Overridden by a bound `SpanExporterInterface` bean. |
| `service-name` | `''` | `service.name`; empty falls back to `app.name`. Shared with structured logging. |
| `resource-attributes` | `[]` | Extra scalar resource attributes. |
| `sampler.type` | `always_on` | `always_on` \| `always_off` \| `ratio`. |
| `sampler.ratio` | `1.0` | The share of new traces kept when `type=ratio` (0..1). |
| `otlp.endpoint` | `http://localhost:4318` | Collector base URL. |
| `otlp.protocol` | `http/protobuf` | `http/protobuf` \| `http/json` \| `grpc`. |
| `otlp.headers` | `''` | `k=v,k2=v2` or a map. |
| `http-server.enabled` | `true` | The SERVER span filter (under the master gate). |
| `http-server.exclude` | management base path + `/*` | Glob list of paths that get no span; **replaces** the default when set. |
| `http-client.enabled` | `true` | CLIENT spans + `traceparent` on the `Http` client. |
| `cqrs.enabled` | `true` | INTERNAL spans on the command/query buses. |
| `eda.enabled` | `true` | PRODUCER/CONSUMER spans and `traceparent` in envelopes. |

## Testing

`Firefly\Testing\Double\RecordingTracer` implements the whole port in memory with real W3C-shaped ids:
`recorded(): list<RecordedSpan>`, `find(string $name)`, `ofKind(SpanKind)`, `reset()`, and the M13 `$spans`
list of names. Hand it to a filter or bind it as the `Tracer` and assert on `RecordedSpan`'s public fields
(`name`, `kind`, `attributes`, `events`, `status`, `statusDescription`, `exception`, `parent`, `ended`). For an
end-to-end suite over the SDK, bind `OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter` as
`SpanExporterInterface` in `defineFireflyEnvironment()` and read `getSpans()` — what a backend would receive.

## Laravel comparison

| Concern | Plain Laravel | LaraFly |
|---|---|---|
| Request tracing | third-party packages, each with its own middleware and header format | `TracingFilter`, W3C `traceparent`, one `Tracer` port |
| Outbound propagation | manual `withHeaders()` at every call site | a Guzzle middleware on the `Http` factory, once, at boot |
| Async correlation | none first-party | `traceparent` in every `EventEnvelope`, CONSUMER spans on delivery |
| Vendor lock | the tracing package's | OpenTelemetry API/SDK, OTLP to any collector; `RecordingTracer` needs no SDK |

## Known-latent

- **Broker publishers do not yet stamp `traceparent`.** `eda-rabbitmq`, `eda-kafka` and `eda-postgres`
  build their envelopes themselves and do not call `EdaTracing::tracePublish()`; their consume path is traced
  through the shared `SubscriberRegistrySink`, so a `traceparent` a producer DID put in the headers is
  continued. Routing the three `publish()` methods through the seam is a small, contained follow-up.
- **`#[Timed]`/`#[Counted]`/`#[Observed]` method attributes** wait for the method-interceptor chain the
  security wave generalises from the transactional proxy.
- **problem+json's `traceId`** is still the correlation id (what `CorrelationIdFilter::of()` returns), so a
  document and its `X-Correlation-Id` keep agreeing; the W3C trace id is on the exchange row and in the logs.
- **No `traceresponse`**: W3C defines no response header yet; nothing is written on the way out.

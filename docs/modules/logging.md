# Logging

`firefly/observability` decides what a log **line** looks like, on top of Laravel's own `logging.php` (which
still decides where lines go): every record on the configured channels carries the correlation id, the
request id and — with [tracing](tracing.md) on — the trace and span ids; and `firefly.logging.structured.format`
turns those channels' lines into JSON, ECS or Logstash documents without replacing a single handler.

## Log correlation

Two Monolog processors are pushed onto each configured channel's real Monolog logger, the same way Laravel
attaches its own `ContextLogProcessor` — from `Application::boot()` by `LogChannelWiringPass` (the guarantee:
it runs whoever resolved `log` first, and it is where a bad `firefly.logging.structured.*` key refuses the
boot) and, earlier, by `ObservabilityWiringProvider`'s `afterResolving('log')` hook, so a line written before
the wiring passes run already carries the ids; both go through one idempotent `LogChannelWiring`:

- `CorrelationIdLogProcessor` — `extra.correlation_id` from `Context` `firefly.correlation_id`
  (`CorrelationIdFilter`), the id problem+json and the `X-Correlation-Id` header carry.
- `TraceContextLogProcessor` — `extra.trace_id` / `extra.span_id` from the **current span** when the tracer has
  one (a line written inside a command handler or an event listener names *that* span), else from the
  request's ids `TracingFilter` published in `Context`; plus `extra.correlation_id` and `extra.request_id`
  (`RequestContextFilter`). Nothing is added for an id that is not known — a plain-text line with tracing off
  looks exactly as it did.

A channel an application **builds** rather than configures — `Log::build(['driver' => 'single', …])`, the
per-tenant file a job opens — is never seen by that wiring, so `FireflyContextLogProcessor` covers it from the
other side: `LogManager` pushes `Illuminate\Contracts\Log\ContextLogProcessor` onto **every** channel it
creates, and the framework binds that contract to a decorator running Laravel's own processor first and then
the same two above. A configured channel carries both and reads identically (both passes write the same
fields from the same `Context`); an on-demand one now carries the ids as well, though not the structured
formatter — see [Known-latent](#known-latent). `firefly.logging.structured.all-channels` turns the rebinding
off.

## Structured logging

Two keys in `config/firefly.php`, both shipped at their defaults in the reference — `format` is `''` (Laravel's
plain text), `json`, `ecs` or `logstash`, and anything else refuses to boot; `channels` is empty, which means the
`logging.default` channel:

<!-- source: skeleton/config/firefly.php -->
```php
'logging' => [
    'structured' => [
        // …
        'format' => env('FIREFLY_LOG_FORMAT', ''),
        // …
        // 'channels' => ['stack', 'stderr'],
    ],
],
```

`StructuredLogging` sets the formatter on every handler of the listed channels that implements Monolog's
`FormattableHandlerInterface` (and on a `GroupHandler` — the wrapper an `ignore_exceptions` stack puts its
members in — which forwards it without declaring the interface) — a `daily` file stays a daily file, a `stack`
keeps its members (a stack's Monolog logger holds its members' handler *instances*, so formatting the stack
formats the members) — and pushes `ServiceContextLogProcessor` (`extra.service_name` = `tracing.service-name`
or `app.name`, `extra.service_environment` = `app.env`). An unknown format, or a listed channel
`logging.channels` does not define, is a `ConfigurationException` at boot — Laravel would otherwise hand the
typo a throw-away emergency logger while the channel you actually write to silently kept plain text without a
single id. Listing a member as well as its stack, in either order, changes nothing.

### `json` — Monolog's `JsonFormatter`

```json
{"message":"disk almost full","context":{"free":"3%"},"level":300,"level_name":"WARNING","channel":"stack","datetime":"2026-09-20T10:11:12.345678+00:00","extra":{"correlation_id":"4b6f…","trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","span_id":"00f067aa0ba902b7","request_id":"9c1e…","service_name":"ledger","service_environment":"production"}}
```

### `ecs` — Elastic Common Schema 8 (`EcsFormatter`, first-party)

```json
{"@timestamp":"2026-09-20T10:11:12.345678+00:00","log.level":"warning","message":"disk almost full","ecs.version":"8.11.0","log":{"logger":"stack"},"service":{"name":"ledger","environment":"production"},"trace":{"id":"4bf92f3577b34da6a3ce929d0e0e4736"},"span":{"id":"00f067aa0ba902b7"},"labels":{"correlation_id":"4b6f…","request_id":"9c1e…"},"context":{"free":"3%"}}
```

The four framework ids and the two service fields are lifted into their ECS homes; whatever else a caller or
a processor added stays under `context`/`extra` (nested, so an application's `message` or `error` key can
never collide with an ECS field). A `Throwable` under `context.exception` — Laravel's own convention — becomes
`error.{type,message,stack_trace}`. Levels are lowercase, as ECS logging libraries write them.

### `logstash` — Monolog's `LogstashFormatter`

```json
{"@timestamp":"2026-09-20T10:11:12.345678+00:00","@version":1,"host":"web-1","message":"disk almost full","type":"ledger","channel":"stack","level":"WARNING","monolog_level":300,"fields":{"correlation_id":"4b6f…","trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","span_id":"00f067aa0ba902b7","request_id":"9c1e…","service_name":"ledger","service_environment":"production"},"context":{"free":"3%"}}
```

`type` is the service name; the framework's `extra` fields sit under `fields`, the call's context under
`context`.

## Runtime log levels

`POST /actuator/loggers/{name}` (see [Actuator](actuator.md)) still changes a channel's level at runtime; the
format is a boot-time decision.

## Configuration (`firefly.logging.*`, kebab-case)

| Key | Default | Meaning |
|---|---|---|
| `firefly.logging.structured.format` | `''` | `''` \| `json` \| `ecs` \| `logstash`. |
| `firefly.logging.structured.channels` | `[]` | Channel names to format (and to carry the id processors); empty means `logging.default`; a name not defined under `logging.channels` refuses to boot. |
| `firefly.logging.structured.all-channels` | `true` | Bind `ContextLogProcessor` to the framework's decorator, so a channel built after boot (`Log::build()`, a stack a package creates) carries the correlation and trace ids too. Off leaves Laravel's own binding in place and the listed channels unaffected. |
| `firefly.observability.tracing.service-name` | `''` | Shared with tracing: the `service.name` on every line; empty falls back to `app.name`. |

## Laravel comparison

| Concern | Plain Laravel | LaraFly |
|---|---|---|
| Ids on a log line | `Context` dumped into `extra` verbatim (`firefly.correlation_id`, …) | normalised `correlation_id`, `request_id`, `trace_id`, `span_id` fields |
| JSON logs | a `formatter` per channel in `logging.php`, hand-picked | one key, applied to the channels' existing handlers |
| ECS | `elastic/ecs-logging` | first-party `EcsFormatter`, no extra dependency |

## Known-latent

- **`gelf` and `logfmt`** are not offered; Monolog has a `GelfMessageFormatter`, and a channel can still set
  it through Laravel's own `formatter` key.
- **The ids reach every channel, including one built after boot.** `LogManager` hands
  `Illuminate\Contracts\Log\ContextLogProcessor` to every channel it creates — on-demand ones from
  `Log::build()` included — so the framework binds that contract to a decorator carrying the correlation id
  and the W3C trace ids, with Laravel's own context processor preserved **inside** it (the application's
  `Context::add()` values are still written, and the framework's ids are written over a user key of the same
  name rather than under it). What still does **not** reach an on-demand channel is the structured
  **formatter**: a formatter is set on handlers built from a config array this package never sees, and there
  is no container seam for those, so such a channel carries the ids and Monolog's line format. Gated by
  `firefly.logging.structured.all-channels`.

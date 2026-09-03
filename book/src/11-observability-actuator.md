<span class="eyebrow">Part IV — Observability, Testing & Delivery · Chapter 11</span>

# Observability: Health, Metrics, and the Actuator {.chtitle}

By the end of this chapter you will know `firefly/actuator`'s `HealthIndicator` SPI and the built-in `Ping`/`DiskSpace`/`Db` indicators, how `HealthEndpoint` aggregates them into a single `/actuator/health` response — and how a probe **group** (the mechanism behind "liveness" and "readiness") is nothing more than a named, configured subset of indicators, how the whole management surface is **unexposed by default** so a forgotten endpoint fails closed as a 404 rather than an information leak, and `firefly/observability`'s pure-PHP `MeterRegistry`, its locale-safe Prometheus exporter, and the exact `#[Order(500)]` precedence trick — the same one Chapter 10 showed you for security — that lets `MeterRegistryCqrsMetrics` replace the CQRS bus's `NoOpCqrsMetrics` with no code change to `firefly/cqrs` at all. The chapter closes on `firefly/admin`, the server-rendered browser dashboard over those same endpoints — thirteen pages including a drawn **bean graph** that resolves every constructor dependency through the interface it is wired by and reports the cycles a boot would otherwise die on with no message. It reads those endpoints **in-process**, so it renders pages the JSON surface deliberately keeps unexposed, which makes its own URL the entire security boundary and its default (`app.debug`) the most important line in the package.

!!! note "New term: actuator"
    An **actuator** is a management endpoint that reports on the *running process itself* — is it healthy, what did it boot with, how fast are its requests — rather than on the business domain the process serves. The term and the shape both come from Spring Boot Actuator; `firefly/actuator` is a first-party, dependency-light PHP analogue: framework endpoints mounted directly on the same Illuminate `Router` your own controllers use, not a separate admin process.

---

## The `HealthIndicator` SPI

A health check in LaraFly is any `#[Component]` implementing one method:

```php
interface HealthIndicator
{
    public function health(): Health;
}
```

`Health` is an immutable status-plus-details reading, built exclusively through four named factories:

```php
final readonly class Health
{
    public function __construct(
        public Status $status,
        public array $details = [],
    ) {}

    public static function up(array $details = []): self
    {
        return new self(Status::Up, $details);
    }

    public static function down(array $details = []): self
    {
        return new self(Status::Down, $details);
    }

    public static function outOfService(array $details = []): self
    {
        return new self(Status::OutOfService, $details);
    }

    public static function unknown(array $details = []): self
    {
        return new self(Status::Unknown, $details);
    }
}
```

`Status` is a backed enum carrying its own severity ordering and the HTTP status it maps to — DOWN and OUT_OF_SERVICE both render as `503`, so a load balancer needs no special-casing to treat either as "take this instance out of rotation":

```php
enum Status: string
{
    case Up = 'UP';
    case Down = 'DOWN';
    case OutOfService = 'OUT_OF_SERVICE';
    case Unknown = 'UNKNOWN';

    public function severity(): int
    {
        return match ($this) {
            self::Down => 3,
            self::OutOfService => 2,
            self::Up => 1,
            self::Unknown => 0,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Down, self::OutOfService => 503,
            default => 200,
        };
    }
}
```

---

## The built-in indicators: Ping, DiskSpace, Db

Three `HealthIndicator`s ship with `firefly/actuator`, and all three are worth reading end to end — they are short, and each teaches a different design decision.

`PingHealthIndicator` is the trivial always-up probe, and its own docblock names exactly what it is for:

```php
/** The trivial liveness probe — always UP. Discovered by HealthContributorRegistrar under the name 'ping'. */
#[Component]
final class PingHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up();
    }
}
```

`DiskSpaceHealthIndicator` reads a path and a byte threshold from config, and degrades gracefully — an unreadable path is reported DOWN with an explanatory detail rather than throwing:

```php
#[Component]
final class DiskSpaceHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly Config $config) {}

    public function health(): Health
    {
        $path = $this->config->string('firefly.management.endpoint.health.diskspace.path', getcwd() ?: '.');
        $threshold = $this->config->int('firefly.management.endpoint.health.diskspace.threshold', 10_485_760);

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false) {
            return Health::down(['path' => $path, 'error' => 'unable to determine disk space']);
        }

        $details = ['total' => (int) $total, 'free' => (int) $free, 'threshold' => $threshold, 'path' => $path];

        return $free >= $threshold ? Health::up($details) : Health::down($details);
    }
}
```

`DbHealthIndicator` is the one indicator that is **opt-in** rather than on by default — `#[ConditionalOnProperty]` with no `matchIfMissing`, so a skeleton project with no database configured never sees a surprise `DOWN` from a check it never asked for:

```php
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.db.enabled', havingValue: 'true')]
final class DbHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    public function health(): Health
    {
        try {
            $connection = $this->connections->connection();
            $connection->selectOne('select 1 as ok');

            $driverName = method_exists($connection, 'getDriverName') ? $connection->getDriverName() : null;
            $driver = is_string($driverName) ? $driverName : 'unknown';

            return Health::up(['database' => $driver]);
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }
    }
}
```

Notice `DbHealthIndicator` depends directly on Illuminate's own `ConnectionResolverInterface` rather than on anything from `firefly/data` — there is no `Actuator → Data` edge in `deptrac.yaml` at all, so a DB health check costs `firefly/actuator` no new dependency. And every indicator here follows the same fail-safe shape: a query, a comparison, or a filesystem call that could throw is always caught and turned into `Health::down()` with a detail explaining why — never an unhandled exception, never a `500` where a `503` belongs.

---

## Aggregating health: `HealthEndpoint` and probe groups

`HealthEndpoint` is itself a `#[Component]` (not a plain framework internal — it has to be discoverable like any other bean) that reads every registered indicator, runs each one fail-safe, and folds the results down to the single most-severe status:

```php
#[Component]
final class HealthEndpoint implements ActuatorEndpoint
{
    public function __construct(
        private readonly HealthContributorRegistry $registry,
        private readonly StatusAggregator $aggregator,
        private readonly Config $config,
    ) {}

    public function endpointId(): string
    {
        return 'health';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        $indicators = $this->registry->all();

        if ($request->subPath !== []) {
            $members = $this->group($request->subPath[0]);
            if ($members === null) {
                return null; // unknown group → 404
            }
            $indicators = array_intersect_key($indicators, array_flip($members));
        }

        $components = [];
        $statuses = [];
        foreach ($indicators as $name => $indicator) {
            $health = $this->readFailSafe($indicator);
            $statuses[] = $health->status;
            $components[$name] = ['status' => $health->status->value, 'details' => $health->details];
        }

        $status = $this->aggregator->aggregate($statuses);
        $body = ['status' => $status->value];

        if ($this->showDetails()) {
            $body['components'] = $components;
        }

        return EndpointResponse::json($body, $status->httpStatus());
    }

    private function readFailSafe(HealthIndicator $indicator): Health
    {
        try {
            return $indicator->health();
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }
    }

    private function group(string $name): ?array
    {
        $key = "firefly.management.endpoint.health.group.{$name}.include";
        if (! $this->config->has($key)) {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->config->string($key))),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    private function showDetails(): bool
    {
        return $this->config->string('firefly.management.endpoint.health.show-details', 'never') === 'always';
    }
}
```

Two things are worth pausing on. First, with no request path at all, every registered indicator counts and `StatusAggregator` folds an empty set to `UP` (empty means nothing to be unhealthy about) and otherwise picks the single most-severe `Status` by `severity()` — one `DOWN` indicator is enough to bring the whole aggregate down, whatever the others report. Second, **there is no separate "liveness" or "readiness" endpoint class anywhere in the source** — `/actuator/health/{group}` is a single generic mechanism, and "liveness"/"readiness" are simply *conventional names* you configure, not a hard-coded Kubernetes-shaped feature:

```php
<?php

declare(strict_types=1);

return [
    'management' => [
        'endpoint' => [
            'health' => [
                'group' => [
                    'liveness' => ['include' => 'ping'],
                    'readiness' => ['include' => 'ping,db'],
                ],
            ],
        ],
    ],
];
```

Configured this way, `GET /actuator/health/liveness` aggregates only `ping` (so a healthy-but-momentarily-database-less process still reports alive) and `GET /actuator/health/readiness` folds in `db` too — but the mechanism underneath is the exact same `group()` lookup and the exact same `Health`/`Status` types the rest of this section already showed you. A group name nobody configured returns `null` from `group()`, which `handle()` turns into a plain `404`, not a `200` with an empty body.

`show-details` (`never`/`when-authorized`/`always`, default `never`) governs whether the response includes the per-component `details` map at all — with `never`, an unauthenticated caller sees only the aggregate `status`, never *which* indicator failed or why. `when-authorized` is a real, accepted config value, but — because `firefly/actuator` has no code dependency on `firefly/security` at all — it degrades to the same behavior as `never`; a details-only-when-authenticated policy is something you build yourself in front of the endpoint, not something the value switches on.

---

## Secure by default: exposure and the 404

The single most important operational fact about the actuator is this: **most endpoints are unreachable until you say otherwise.** `ExposureModel` is the whole mechanism, and it is short enough to read in full:

```php
final readonly class ExposureModel
{
    public function __construct(
        private array $include,
        private array $exclude,
        public string $basePath,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $include = self::csv($config->string('firefly.management.endpoints.web.exposure.include', 'health,info'));
        $exclude = self::csv($config->string('firefly.management.endpoints.web.exposure.exclude', ''));
        $base = trim($config->string('firefly.management.endpoints.web.base-path', '/actuator'), '/');

        return new self($include, $exclude, $base === '' ? 'actuator' : $base);
    }

    public function isExposed(string $id): bool
    {
        if (in_array($id, $this->exclude, true)) {
            return false;
        }

        if (in_array('*', $this->include, true)) {
            return true;
        }

        return in_array($id, $this->include, true);
    }
}
```

The default `include` is `"health,info"` — every other endpoint id (`env`, `beans`, `conditions`, `mappings`, `loggers`, `scheduledtasks`, and observability's `metrics`/`prometheus` once that package is installed) is **not exposed** until you explicitly add it, and `ActuatorDispatchAction` renders an unexposed or unknown id as a plain `404` through the same `ProblemDetailsRenderer` Chapter 4 introduced — never a raw framework error, and never a silent `200` with a body an unauthenticated caller shouldn't see. `.exclude` always wins over `.include`, so `include: '*'` plus a short `exclude` list is a legitimate "expose everything except…" policy.

`/actuator/env` layers a second, independent safety net on top of exposure: even once exposed, any key whose name matches `password|secret|token|key|credential|passwd` (case-insensitive, recursive through nested arrays) is masked before the response is built — defense in depth for an endpoint that is reachable at all only once you have opted in:

```php
#[Component]
final class EnvEndpoint implements ActuatorEndpoint
{
    private const MASK = '******';

    private const SENSITIVE = '/password|secret|token|key|credential|passwd/i';

    public function __construct(private readonly Repository $config) {}

    public function endpointId(): string
    {
        return 'env';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $firefly = (array) $this->config->get('firefly', []);

        return EndpointResponse::json(['firefly' => $this->mask($firefly)]);
    }

    private function mask(array $values): array
    {
        $masked = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->mask($value);

                continue;
            }
            $masked[$key] = preg_match(self::SENSITIVE, (string) $key) === 1 ? self::MASK : $value;
        }

        return $masked;
    }
}
```

Beyond exposure and masking, `firefly/security`'s `HttpSecurity` (Chapter 10) is what actually locks the surface down for real traffic, and it needs **zero** code changes to do it — `HttpSecurityFilter` is a global middleware, so it runs for the actuator's own directly-registered routes exactly as it runs for your controllers:

```php
<?php

declare(strict_types=1);

return [
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                ['pattern' => 'actuator/info', 'access' => 'permitAll'],
                ['pattern' => 'actuator/*', 'access' => 'hasRole:ACTUATOR'],
            ],
        ],
    ],
];
```

`firefly/actuator`'s own `composer.json` has no dependency on `firefly/security` at all — securing the actuator this way is **pure configuration**, drawing on the same deny-by-default URL DSL Chapter 10 already taught you, with no new mechanism to learn.

!!! warning "Every other management endpoint is a standalone endpoint, not an `/info` sub-key"
    `/actuator/info` genuinely has exactly three fragments — `runtime` (from `RuntimeInfoContributor`, registered by default, which is why a freshly generated application already answers something: PHP version/SAPI/OPcache, Laravel version, LaraFly version, current and peak memory; turn it off with `firefly.management.info.runtime.enabled = false`), `app` (from `AppInfoContributor`, read from `firefly.management.info.app.*`) and `build` (from `BuildInfoContributor`, reading a JSON file at `firefly.management.info.build.path`). `env`, `beans`, `conditions`, `mappings`, `loggers`, `scheduledtasks`, `configprops`, and `caches` are each their **own** `ActuatorEndpoint`, mounted at their own `/actuator/{id}` — not nested under `/info`. `firefly:about` (Chapter 13) renders several of these together at the terminal, which is a convenience of that one command, not evidence they share a route.

---

## The HAL index and the endpoint contract

Every framework endpoint — health, info, metrics, or your own — implements the same three-method contract:

```php
interface ActuatorEndpoint
{
    /** The stable id under the base path, e.g. 'health' → /actuator/health. */
    public function endpointId(): string;

    /** Per-endpoint kill switch; ActuatorDispatchAction also honours firefly.management.endpoint.{id}.enabled. */
    public function enabled(): bool;

    public function handle(EndpointRequest $request): ?EndpointResponse;
}
```

`ActuatorRouteRegistrar`, a `BootPass` at `WiringPasses` phase, discovers every `#[Component]` implementing this interface, resolves each one exactly once, and mounts exactly **two** native Illuminate routes — a `GET {base}` index and a catch-all `GET|POST {base}/{path}` dispatcher — under the configured base path, so nothing about the mechanism collides with your own application routes. The index itself, `ActuatorIndexAction`, renders a HAL-style `_links` map filtered to whatever is both enabled **and** exposed:

```php
final class ActuatorIndexAction
{
    public function __construct(
        private readonly ActuatorRegistry $registry,
        private readonly ExposureModel $exposure,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost().'/'.$this->exposure->basePath, '/');

        $links = ['self' => ['href' => $base]];
        foreach ($this->registry->all() as $id => $endpoint) {
            if (! $endpoint->enabled() || ! $this->config->bool("firefly.management.endpoint.{$id}.enabled", true) || ! $this->exposure->isExposed($id)) {
                continue;
            }
            $links[$id] = ['href' => $base.'/'.$id];
        }

        return new Response(
            (string) json_encode(['_links' => $links], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
```

`GET /actuator` on a default install therefore lists only `self`, `health`, and `info` — exactly the two ids `include` exposes by default — and grows only as far as your own `.include` configuration lets it.

---

## Metrics: `MeterRegistry`, `MetricsRecorder`, and idempotent registration

`firefly/observability` is a second, independent opt-in package layered on top of the actuator — it ships its own `MeterRegistry` (the read-facing factory a consumer queries) and a narrower `MetricsRecorder` (the write-facing port instrumentation actually depends on, so a filter or a metrics recorder never needs the full registry API):

```php
interface MeterRegistry
{
    /** @param array<string, string> $tags */
    public function counter(string $name, array $tags = []): Counter;

    /** @param array<string, string> $tags */
    public function timer(string $name, array $tags = []): Timer;

    /**
     * @param  array<string, string>  $tags
     * @param  callable(): float  $supplier
     */
    public function gauge(string $name, array $tags, callable $supplier): Gauge;

    /** @return list<Meter> */
    public function meters(): array;
}
```

```php
interface MetricsRecorder
{
    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void;

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void;

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void;
}
```

`SimpleMeterRegistry` implements both ports as a first-party, pure-PHP, in-memory store — no `ext-prometheus`, no OpenTelemetry SDK. Registration is idempotent, keyed by `type|name|sorted-tags`, so calling `counter('cqrs_commands_seconds', [...])` twice with the same name and tag set returns the very same `Counter` instance both times. It also fails loud, on purpose, if you try to register one metric name under two different types:

```php
final class SimpleMeterRegistry implements MeterRegistry, MetricsRecorder
{
    private function guardType(string $name, MeterType $type): void
    {
        $existing = $this->namesToTypes[$name] ?? null;
        if ($existing !== null && $existing !== $type) {
            throw new InvalidArgumentException(
                "Metric '{$name}' already registered as {$existing->value}; cannot re-register as {$type->value}."
            );
        }
        $this->namesToTypes[$name] = $type;
    }
}
```

This is not pedantry: Prometheus's own text format scopes exactly one `# TYPE` declaration per metric name, so a name registered as both a counter and a gauge somewhere in your codebase would make `PrometheusTextFormat` emit two conflicting `# TYPE` lines for the same name — invalid exposition that a scraper would reject. `SimpleMeterRegistry` turns that mistake into an immediate `InvalidArgumentException` at the call site responsible, rather than a scrape failure discovered later in production.

---

## Exposition: the locale-safe Prometheus exporter

`PrometheusTextFormat` renders every registered meter as format-0.0.4 text at `/actuator/prometheus`. The interesting part is a six-line private method most teams get wrong the first time they write one:

```php
final class PrometheusTextFormat
{
    private function value(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        // number_format() (unlike sprintf('%f')) is locale-INDEPENDENT here: the decimal point and thousands
        // separator are passed explicitly as arguments, so LC_NUMERIC (e.g. a comma-decimal locale such as
        // de_DE) cannot leak a ',' into the exposition and produce unscrapeable Prometheus output.
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }
}
```

`sprintf('%f', $value)` is the instinctive way to format a float in PHP, and it is *locale-dependent*: on a host whose `LC_NUMERIC` is set to a comma-decimal locale like `de_DE`, `sprintf('%f', 1.5)` renders `"1,500000"` — a comma the Prometheus text format's own grammar does not permit inside a sample value, silently producing exposition a scraper cannot parse. `number_format($value, 10, '.', '')` takes the decimal point and thousands separator as **explicit arguments**, so the process's ambient locale can never leak into the output — the same class of "which layer controls formatting" question the rest of this book keeps circling back to, here answered once, in one function, rather than at every call site that happens to print a float.

`/actuator/metrics` (`MetricsEndpoint`) exposes the same registry as Micrometer-flavored JSON instead — no sub-path lists every registered name; a specific name resolves to its measurements and available tags, or a plain `404` if the registry has never seen it.

---

## Auto-instrumentation: `MetricsFilter` and bounded cardinality

Once `firefly/observability` is installed and enabled, every HTTP request is timed automatically by `MetricsFilter`, a `#[Component]` `WebFilter` discovered by `firefly/web`'s filter chain with no wiring of your own:

```php
#[Component]
#[Order(-100)]
#[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class MetricsFilter extends OncePerRequestFilter
{
    private const string UNMATCHED_ROUTE_URI = 'UNKNOWN';

    public function __construct(private readonly MetricsRecorder $recorder) {}

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $start = microtime(true);

        try {
            $response = $next($request);
            $status = $response instanceof Response ? $response->getStatusCode() : 200;
            $this->record($request, $start, $status, $this->outcome($status), 'none');

            return $response;
        } catch (Throwable $e) {
            $this->record($request, $start, 500, 'SERVER_ERROR', $e::class);

            throw $e;
        }
    }

    private function record(Request $request, float $start, int $status, string $outcome, string $exception): void
    {
        $this->recorder->record('http_server_requests_seconds', [
            'method' => $request->getMethod(),
            'uri' => $request->route() !== null ? '/'.ltrim((string) $request->route()->uri(), '/') : self::UNMATCHED_ROUTE_URI,
            'status' => (string) $status,
            'outcome' => $outcome,
            'exception' => $exception,
        ], microtime(true) - $start);
    }
}
```

Two details make this production-safe rather than merely convenient. `#[Order(-100)]` puts it outermost among discovered filters, so it times the *entire* inner chain — security, validation, your controller — not just a slice of it. And the `uri` tag is always the matched route's **template** (`/orders/{id}`), never the raw request path — an unmatched request (any `404`) is tagged with the fixed sentinel `'UNKNOWN'` rather than the attacker- or crawler-controlled path itself. Tagging by raw path would let anyone generate an unbounded number of distinct metric label combinations just by hitting made-up URLs; under Octane, where the registry is a process-lifetime singleton rather than a fresh one per request, that is not a cosmetic wart — it is an unbounded memory-growth vector. On a thrown request, the filter records the outcome and **rethrows** rather than swallowing, so `ProblemDetailsRenderer` still gets to render the error exactly as it would without the filter installed.

`MeterBindingsPass`, a companion `BootPass`, registers a handful of pull-based gauges the same way: `process_resident_memory_bytes`/`php_memory_peak_bytes` for the running process, and one `resilience_circuit_breaker_state{name}` gauge per configured circuit breaker (`closed=0`, `open=1`, `half_open=2`) — each sampled *live* at scrape time via a closure, so a fresh `/actuator/prometheus` request always reflects the breaker's current state rather than a snapshot from boot.

---

## The CQRS metrics seam: `#[Order(500)]`, one more time

Chapter 7 left you with `NoOpCqrsMetrics` bound behind `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, and a docblock promising a real recorder would drop in later. `firefly/observability` is that drop-in, and the winning mechanism is *exactly* the precedence trick Chapter 10 used for `SecurityCommandAuthorizer`:

```php
#[Configuration]
#[Order(500)]
final class ObservabilityAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    #[ConditionalOnMissingBean(MeterRegistry::class)]
    public function meterRegistry(): MeterRegistry
    {
        return new SimpleMeterRegistry;
    }

    #[Bean]
    #[ConditionalOnMissingBean(CqrsMetrics::class)]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    public function cqrsMetrics(MetricsRecorder $recorder): CqrsMetrics
    {
        return new MeterRegistryCqrsMetrics($recorder);
    }
}
```

`ObservabilityAutoConfiguration` is `#[Order(500)]`, strictly below `CqrsAutoConfiguration`'s `#[Order(1000)]`. The incremental condition pass evaluates low-`#[Order]`-first and registers survivors immediately, so this class's `cqrsMetrics()` bean registers **first**; by the time `CqrsAutoConfiguration` evaluates its own `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, a bean is already bound, and the `NoOp` default backs off. No line inside `firefly/cqrs` changes — the win is pure auto-configuration ordering, the same shape you have now seen twice.

`MeterRegistryCqrsMetrics` itself is the concrete recorder that ordering installs — a small, direct implementation of the `CqrsMetrics` port, recording each command or query as a timer tagged by message type and outcome:

```php
final class MeterRegistryCqrsMetrics implements CqrsMetrics
{
    public function __construct(private readonly MetricsRecorder $recorder) {}

    public function recordCommandSuccess(object $command, float $seconds): void
    {
        $this->recorder->record('cqrs_commands_seconds', ['type' => $this->type($command), 'outcome' => 'success'], $seconds);
    }

    public function recordCommandFailure(object $command, float $seconds): void
    {
        $this->recorder->record('cqrs_commands_seconds', ['type' => $this->type($command), 'outcome' => 'failure'], $seconds);
    }

    public function recordQuerySuccess(object $query, float $seconds): void
    {
        $this->recorder->record('cqrs_queries_seconds', ['type' => $this->type($query), 'outcome' => 'success'], $seconds);
    }

    public function recordQueryFailure(object $query, float $seconds): void
    {
        $this->recorder->record('cqrs_queries_seconds', ['type' => $this->type($query), 'outcome' => 'failure'], $seconds);
    }

    private function type(object $message): string
    {
        $class = $message::class;

        return ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
    }
}
```

Every observability bean gates on the **same** property, `firefly.observability.metrics.enabled` — not on `MeterRegistry`'s presence. That is a deliberate choice, not an oversight: a `#[ConditionalOnBean(MeterRegistry::class)]` on `cqrsMetrics()` or `MetricsFilter` would be evaluated *before* `ObservabilityAutoConfiguration`'s own `#[Order(500)]` has registered `MeterRegistry` at all, wrongly dropping every downstream consumer even with metrics genuinely enabled — an ordering trap, not a logic bug. Gating everything on one flag instead makes survival order-independent: flip `firefly.observability.metrics.enabled` and the registry, the CQRS recorder, the filter, and both endpoints all survive — or all back off — together.

Finally, a `Tracer` port rounds out the package — a minimal `trace(string $name, callable $callback): mixed` span abstraction, shipped today only as `NoOpTracer` (it simply runs the callback), written against the interface so an OpenTelemetry-backed adapter can drop in later with zero call-site changes — the identical "port now, adapter later" shape you have now seen for `CqrsMetrics` itself.

---

## The admin dashboard: `firefly/admin`

Everything so far in this chapter is JSON, and JSON is the right shape for a load balancer, a Kubernetes probe and a Prometheus scraper. It is not the right shape for a person at 3am who wants to know whether this process compiled its manifests, which auto-configuration backed off, and what `firefly.data.*` actually resolved to. `firefly/admin` is a third opt-in package for that person: a server-rendered browser dashboard over the very same actuator endpoints, in the spirit of Spring Boot Admin.

```bash
composer require firefly/admin
```

Then open `/firefly`. There is no npm step at install time and no CDN at request time — the views are plain Blade with inline CSS and system fonts, because a Composer package cannot assume npm has run, and a dashboard that needs the network is useless in exactly the isolated environments where you most want to look at one.

Thirteen pages, each a view over one endpoint's payload. The menu groups them the way an operator thinks rather than the way the packages are laid out — what is it doing right now, what did it wire at boot, and how is it configured — because a flat list of thirteen links is a worse menu than three short ones:

| Group | Page | Reads | Answers |
|---|---|---|---|
| Runtime | Overview | several | Is it healthy, what is it doing, and what did it wire? |
| Runtime | Health | `health` | Every indicator this process registered, with its own status and details |
| Runtime | Metrics | `metrics` | Counters, timers and gauges, with their current measurements |
| Runtime | HTTP traffic | `httpexchanges` | The most recent requests this application served |
| Wiring | Beans | `beans` | Every bean the container registered, with the stereotype that declared it |
| Wiring | Bean graph | `beans` | How your beans depend on one another, resolved through the interfaces they are wired by |
| Wiring | Conditions | `conditions` | Which auto-configurations applied, and which backed off because you supplied your own |
| Wiring | Routes | `mappings` | The compiled route table the dispatcher serves from |
| Wiring | Scheduled | `scheduledtasks` | Methods registered by `#[Scheduled]`, with the cron or interval that drives them |
| Configuration | Environment | `env` | Resolved `firefly.*` configuration, with secrets masked |
| Configuration | Config properties | `configprops` | Every `#[ConfigProperties]` DTO the application bound, with the values it resolved |
| Configuration | Caches | `caches` | The cache stores this application has configured |
| Configuration | Loggers | `loggers` | Log channels and their levels, with a control to change one |

A page whose endpoint is not registered in *this* process — or is switched off — is **hidden from the menu** rather than offered as a link that lands on an apology. That matters because the actuator's endpoints are conditional: `metrics` disappears when `firefly.observability.metrics.enabled` is false, and several others exist only if the package that contributes them is installed. The menu has to be built from what this process actually registered, so it is.

---

### It reads endpoints in-process, not over HTTP

The dashboard holds the `ActuatorRegistry` and invokes each `ActuatorEndpoint` bean directly:

```php
final readonly class AdminEndpointReader
{
    public function __construct(
        private ActuatorRegistry $registry,
        private Config $config,
        private ?Container $container = null,
    ) {}

    /** The endpoint ids that are registered AND not switched off, in registration order. */
    public function available(): array
    {
        $ids = [];
        foreach ($this->registry->all() as $id => $endpoint) {
            if ($endpoint->enabled() && $this->config->bool("firefly.management.endpoint.{$id}.enabled", true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function read(string $id, array $subPath = [], array $query = []): ?array
    {
        $endpoint = $this->registry->get($id);
        if ($endpoint === null || ! $this->has($id)) {
            return null;
        }

        try {
            $response = $endpoint->handle(new EndpointRequest('GET', $subPath, $query));
        } catch (Throwable) {
            return null;
        }

        return $response === null || is_string($response->body) ? null : $response->body;
    }
}
```

Look at what is **not** in that method: any mention of `ExposureModel`. That is the single most important thing to understand about this package, and it is deliberate. `firefly.management.endpoints.web.exposure.include` defaults to `health,info`, so fetching `/actuator/beans` or `/actuator/env` over HTTP would 404 — as the whole first half of this chapter insisted it should. The dashboard needs none of that. It renders what the process already knows, in-process, so **it shows you pages the HTTP surface deliberately does not expose**, and the JSON surface stays secure-by-default. Exposing `beans`, `conditions` and `env` to every anonymous caller just so a browser could read them would be exactly the wrong trade.

The per-endpoint kill switch *is* honoured, and the asymmetry is the point: `firefly.management.endpoint.{id}.enabled` means "this endpoint is off", which is a statement about the endpoint itself; `exposure.include` means "this endpoint is unpublished", which is a statement about the HTTP surface. The dashboard is not the HTTP surface.

A throwing endpoint is caught and reported as `null` rather than allowed to take the page down with it — the same fail-safe discipline `HealthEndpoint::readFailSafe()` applies to indicators, for the same reason: one broken contributor should degrade its own panel, not the dashboard.

!!! note "Health details are read from the contributor registry, not through the endpoint"
    `show-details` defaults to `never`, and that default is right — it stops an anonymous HTTP caller learning your database host from a failed connection. Applying that HTTP disclosure policy to the dashboard, though, produced a Health panel whose entire content was an apology telling the operator to go and change a config key. The dashboard reads `HealthContributorRegistry` directly instead, calling each indicator in isolation so one that throws is reported DOWN with its reason and nothing else is affected.

---

### The bean graph

Twelve of the thirteen pages are tables. The thirteenth draws a picture, and it is the one that pays for the package on the day something is wired wrongly.

`/actuator/beans` tells you *which* beans exist. It cannot tell you what each one is **wired to**, which is what you actually want when a `#[ConditionalOnMissingBean]` did not fire the way you expected, when an eager singleton cycle has hung a boot with no message, or when you are trying to work out what a package you just installed attached itself to. `/firefly/graph` answers that, as a layered SVG diagram plus a filterable relations table.

Nothing is reflected to build it. `ComponentScanner` already records, at **scan** time, the class and interface types each component's constructor asks for, and that list rides the compiled manifest exactly like every other scanned fact (abridged):

```php
final class ComponentDescriptor
{
    public function __construct(
        public string $class,
        public string $stereotype,
        public array $interfaces,
        /**
         * The class types this component's constructor asks for — the edges of the bean graph.
         *
         * Recorded at scan time, where reflection is already sanctioned, because the alternative is
         * reflecting at request time to answer "what depends on what", which the reflection-free boot
         * contract forbids. Only CLASS and INTERFACE types are kept: a scalar or a builtin is
         * configuration, not a wiring edge, and putting it in the graph would drown the edges that matter.
         */
        public array $dependencies = [],
    ) {}
}
```

That last sentence is a design decision worth pausing on. A constructor parameter typed `string $name` is configuration; drawing it as an edge would bury the relationships that matter under `string`/`int` noise. A **nullable or defaulted class** parameter *is* kept, because an optional collaborator is still a relationship.

#### The hard part is not drawing, it is resolving

A constructor asks for a **type**, and that type is very often an interface — `EventPublisher`, `HealthIndicator`, `Cache` — while the bean that satisfies it is a concrete class that merely implements it. An edge list built naively from constructor types therefore points at nodes that do not exist, and the graph comes out as a field of disconnected dots. Ask yourself what `WalletService`'s dependency on `WalletRepository` should draw an arrow *to*: not to the port, which is an interface with no bean of its own, but to `EloquentWalletRepository`, which is the thing that will actually be constructed.

So every dependency is resolved through an interface index before it becomes an edge:

```php
foreach ($rows as $class => $row) {
    foreach ($row['dependencies'] as $dependency) {
        $target = isset($rows[$dependency]) ? $dependency : ($byInterface[$dependency] ?? null);

        if ($target === null || $target === $class) {
            // A type nothing in the container provides: a framework contract satisfied by a binding
            // rather than a bean, or a class the scan never saw. Reported, not silently dropped —
            // "why is my bean not in the graph" is exactly the question this page has to answer.
            if ($target === null) {
                $unresolved[] = $dependency;
            }

            continue;
        }

        $edges[] = ['from' => $class, 'to' => $target, 'via' => $target === $dependency ? null : $dependency];
    }
}
```

The `via` member is the honesty in that loop. When the edge went through an interface, the diagram marks it and the Relations table's **Wired by** column names the interface, so a reader can see the indirection rather than being quietly shown a relationship they never wrote. When the constructor named the concrete class, the column just says `class`.

The index is built in catalogue order and **first implementor wins**, deterministically — the catalogue is emitted in scan order, so the same application always draws the same graph rather than reshuffling between machines. An interface with several implementors is a real ambiguity that the container resolves with `#[Primary]`/`#[Qualifier]`, and the graph says so by listing the edge as `via` rather than pretending the choice was obvious.

#### Layers, cycles, and the node ceiling

Levels come from a **longest-path** walk over the resolved edges: a node's depth is one more than the deepest thing it depends on, and the levels are then flipped so level 0 holds the things nothing depends on. The effect is that a node always sits below everything that depends on it, arrows read consistently downward, and the eye can follow a chain from a controller to the repository at the bottom of it. The view only positions; the levels come from the model.

Depth is memoised and the walk carries its own visited set, so a cycle terminates instead of recursing forever — and the edge that closed it is *reported*:

```php
foreach ($out[$node] ?? [] as $next) {
    if (isset($path[$next])) {
        $cycles[] = ['from' => $node, 'to' => $next];

        continue;
    }
    $deepest = max($deepest, $walk($next, $path) + 1);
}
```

That reporting is worth more than it looks. The container has no cycle detection of its own, so a cycle among eager singletons does not produce a helpful error — it exhausts memory at boot. A page that names the two classes involved turns "the app died with no message" into a five-second diagnosis, and the panel's own advice is the right one: break one of these edges, usually by injecting an interface and letting the other side depend on that.

Two limits are stated in the page rather than hidden:

* **Past `firefly.admin.graph.max-nodes` — 220 by default — the diagram is suppressed** and the Relations table below carries the same information as a filterable list. A diagram past a couple of hundred nodes is a hairball, not something a person can read, and rendering one anyway would be a worse answer than declining to. It is a config key rather than a constant because "unreadable" depends on the screen and the application.
* **"Provided outside the container" is not a warning.** Those chips are constructor types satisfied by a Laravel container binding rather than a scanned bean — the `Request`, the config repository, a connection. They are listed rather than silently dropped precisely because *"why is my bean not in the graph"* is the question the page has to answer. A type appearing there that you expected to be a bean of *yours* means your scan did not see it, and `firefly.scan.paths` is the first thing to check.

!!! tip "Read it next to the Conditions page"
    The two answer complementary halves of every auto-configuration surprise. **Conditions** says *whether* a framework bean was registered or backed off, and on which condition. **The graph** says what the bean that did win is wired to, and through which interface. An `EventPublisher` edge pointing at `InMemoryEventPublisher` when you configured `firefly.eda.provider=rabbitmq` is one glance on the graph; Conditions then names the `#[ConditionalOnProperty]` that did not match.

!!! note "What the graph does not draw yet"
    Edges come from constructor `dependencies` only. `BeansCatalog` also publishes each `#[Bean]` factory method's own parameters (under `produces`), but `BeanGraph` does not read them, so a `#[Configuration]` class appears with the edges *its own constructor* declares and the wiring its `#[Bean]` methods perform is not drawn. That under-draws framework auto-configuration classes specifically; your `#[Service]`/`#[Repository]` beans, which wire through constructors, are drawn in full.

---

### The access model is the whole security boundary

Because the dashboard bypasses exposure, its own URL is the only thing standing in front of `beans`, `env` and `conditions`. That is why it must not be on by default in production, and why the enable flag is written the way it is:

```php
final readonly class AdminSettings
{
    public function __construct(
        public bool $enabled,
        public string $basePath,
        public string $title,
        // ... plus the presentation options: refreshSeconds, theme, graphMaxNodes, excludedPages.
    ) {}

    public static function fromConfig(Config $config): self
    {
        $base = trim($config->string('firefly.admin.base-path', '/firefly'), '/');

        return new self(
            enabled: $config->bool('firefly.admin.enabled', $config->bool('app.debug', false)),
            basePath: $base === '' ? 'firefly' : $base,
            title: $config->string('firefly.admin.title', $config->string('app.name', 'LaraFly')),
            // ... firefly.admin.refresh-seconds (10, floored at 2), .theme (auto|light|dark),
            // .graph.max-nodes (220) and .pages.exclude ('') are read here too.
        );
    }
}
```

`firefly.admin.enabled` **defaults to the value of `app.debug`**. The reasoning is that an application already running with debug on is already serving stack traces to whoever asks and is a development environment by definition, so a dashboard there discloses nothing that was not already disclosed. An application with debug off has made the opposite statement about itself, and must opt in explicitly. Setting the key always wins over the debug default, in both directions — you can turn the dashboard off in a debug environment, and on in a production one.

!!! warning "Turning it on outside debug is only half the job"
    `firefly.admin.enabled = true` with `app.debug = false` mounts a dashboard that renders your bean graph, your resolved configuration and your route table at a known URL, to anyone who can reach it. The dashboard ships **no authentication of its own** — it has no code dependency on `firefly/security` at all, exactly as `firefly/actuator` does not. An application that turns it on outside debug **must put the route behind its own auth middleware**.

Chapter 10's `HttpSecurity` rules do that as pure configuration, the same way this chapter already secured the actuator — here is a deployment that opts in and locks the route down in one file:

```php
<?php

declare(strict_types=1);

return [
    'admin' => [
        'enabled' => true,          // explicit: this deployment wants the dashboard with app.debug off
        'base-path' => '/firefly',
    ],
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'firefly', 'access' => 'hasRole:ADMIN'],
                ['pattern' => 'firefly/*', 'access' => 'hasRole:ADMIN'],
            ],
        ],
    ],
];
```

`AdminRouteRegistrar` mounts the two routes — an index and a `GET|POST {base}/{page}` catch-all — as a `BootPass` at `WiringPasses`, order **60**, one step after `ActuatorRouteRegistrar`'s 50, because it reads the registry that pass populates. They are registered natively on the Illuminate `Router` for the same reason the actuator's and the OpenAPI package's routes are: `firefly.admin.base-path` has to be settable per application, and an attribute route bakes its literal path into a compiled `RouteDescriptor`. When the dashboard is disabled the pass registers *nothing at all* — there is no route to guess at and no handler to reach.

It also backs off silently in one more case that is easy to miss. Blade is required to render the dashboard and is not a dependency of the package, so a JSON-only deployment with no view factory bound gets no routes rather than routes that would fatal on first request; the JSON actuator remains the management surface there.

!!! warning "Three things the dashboard can only show you about *this* process"
    Under PHP-FPM every request is a different process, and three pages inherit that. **Changing a log level** calls the same endpoint `POST /actuator/loggers/{name}` does, which mutates the current process's Monolog handlers — the next request is a different process, so change `logging.channels` for anything that must persist. **Metrics** are only as durable as the registry: the default `SimpleMeterRegistry` keeps meters in process memory, so the dashboard sees only its own request unless `firefly.observability.metrics.store` points at a cache store. And **health details** stay hidden on the JSON `/actuator/health` response until `firefly.management.endpoint.health.show-details` is `always`, even though the dashboard's own Health page reads the indicators directly.

!!! laravel "Laravel parity"
    Plain Laravel ships no health-check or metrics endpoint at all — most teams either hand-roll a `/health` route or reach for a third-party package, usually paired with the `ext-prometheus` extension. `firefly/actuator` and `firefly/observability` are first-party, dependency-light analogues of Spring Boot Actuator and Micrometer respectively: framework endpoints mounted on the same `Router` your app already uses, health checks that reuse Laravel's own `DB`/`Log`/config underneath, and a pure-PHP Prometheus exporter with no extension requirement. Both packages are opt-in Composer dependencies and both are secure-by-default — an app that adds `firefly/actuator` gets `health`/`info` and nothing else until it configures more. `firefly/admin` completes the set as the analogue of Spring Boot Admin, with the difference that it is not a separate monitoring application you deploy and register instances with: it is Blade views inside the application it reports on, which is why it can read the registry directly and why its access model matters as much as it does.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `HealthIndicator` | One-method SPI; a `#[Component]` bean discovered and aggregated automatically |
| `Health` / `Status` | Immutable reading + a severity-ordered enum; DOWN/OUT_OF_SERVICE both map to HTTP 503 |
| `PingHealthIndicator` / `DiskSpaceHealthIndicator` / `DbHealthIndicator` | Always-up liveness probe; threshold-based disk check; opt-in `SELECT 1` DB check |
| `HealthEndpoint` | Aggregates to the most-severe status; a probe **group** is just a configured, named indicator subset — there is no separate liveness/readiness endpoint class |
| `ExposureModel` | `include`/`exclude` CSV gate; default `"health,info"`; everything else is a plain 404 until exposed |
| `EnvEndpoint` | Masks `password\|secret\|token\|key\|credential\|passwd` keys with `******`, independent of exposure |
| `MeterRegistry` / `MetricsRecorder` | Read/write metrics ports; `SimpleMeterRegistry` is idempotent per `type\|name\|tags` and fails loud on a type conflict |
| `PrometheusTextFormat` | Locale-safe exposition — `number_format()`, never `sprintf('%f')` |
| `MetricsFilter` | `#[Order(-100)]` outermost timing filter; tags by route **template**, never raw path — bounded cardinality |
| `ObservabilityAutoConfiguration` `#[Order(500)]` | The same precedence trick as Chapter 10's security seam: registers `cqrsMetrics()` before `CqrsAutoConfiguration` evaluates its `#[ConditionalOnMissingBean]` |
| `firefly/admin` | A server-rendered Blade dashboard at `/firefly`; thirteen pages, and one whose endpoint is unregistered or switched off is hidden from the menu rather than linked |
| `AdminEndpointReader` | Invokes each `ActuatorEndpoint` **in-process** from `ActuatorRegistry`, bypassing `ExposureModel` — so the dashboard shows what the HTTP surface does not expose, and a throwing endpoint degrades one panel |
| `BeanGraph` | Turns the beans catalogue into a drawn dependency graph: constructor edges resolved through an interface index (marked `via`), longest-path layering, cycles reported rather than hung on, and the diagram suppressed past `firefly.admin.graph.max-nodes` (220) |
| `ComponentDescriptor::$dependencies` | The graph's edges, recorded by `ComponentScanner` at **scan** time — class and interface types only, because a scalar parameter is configuration, not wiring |
| `firefly.admin.enabled` | Defaults to `app.debug`; an explicit value wins in both directions, and turning it on with debug off obliges you to put your own auth middleware in front of the route |

---

## Try it yourself {.exercises}

1. **Add a custom `HealthIndicator`.** Write a `#[Component]` implementing `HealthIndicator` that checks something specific to your own app (a feature flag, a queue depth, a cache connection) and confirm it appears in `GET /actuator/health`'s `components` map once `show-details` is set to `always`.
2. **Configure a real liveness/readiness split.** Add `firefly.management.endpoint.health.group.liveness.include = 'ping'` and `...readiness.include = 'ping,db'` (with the DB indicator enabled) to a scratch project's config, and confirm `GET /actuator/health/liveness` and `GET /actuator/health/readiness` diverge the moment you make the database unreachable.
3. **Watch the CQRS metrics seam win the race.** Install `firefly/observability` into a scratch project already using `firefly/cqrs`, send a handful of commands, and inspect `GET /actuator/prometheus` for `cqrs_commands_seconds` samples — then temporarily comment out `ObservabilityAutoConfiguration`'s `#[Order(500)]` attribute (reverting to the class default) and confirm whether the metric still appears, to see the ordering trick actually matter rather than just reading about it.
4. **Prove the dashboard's exposure bypass to yourself.** Install `firefly/admin` in the sample, leave `firefly.management.endpoints.web.exposure.include` at its default, and confirm that `GET /actuator/beans` returns a `404` while `/firefly/beans` renders the full bean list in the same process. Then set `firefly.management.endpoint.beans.enabled` to `false` and confirm the Beans entry vanishes from the dashboard's menu — the kill switch is honoured where exposure is not, and the difference between the two keys is the whole design.
5. **Draw your own wiring, then break it.** Open `/firefly/graph` in the sample and find the arrow from `WalletService` to `EloquentWalletRepository` — note that the *Wired by* column says `WalletRepository`, the port, not `class`. Then introduce a deliberate cycle (have a `#[Service]` take a constructor parameter typed as another `#[Service]` that already depends on it), reload the page, and confirm the **Cycles** stat turns red and names both classes. Now boot the app fresh without opening the dashboard, and compare what PHP tells you about the same cycle.
6. **Read the access default as a security decision.** Set `app.debug` to `false` in a scratch project with `firefly/admin` installed and confirm `/firefly` is genuinely unrouted rather than merely unlinked (`php artisan route:list` should not list it). Then set `firefly.admin.enabled` to `true` without adding any `HttpSecurity` rule, and look at what an unauthenticated `GET /firefly/env` now discloses — that is precisely the gap this chapter told you to close with your own auth middleware.

# Resilience

`firefly/resilience` is LaraFly's resilience layer (a Resilience4j analog): six **programmatic** patterns —
`Retry`, `CircuitBreaker`, `RateLimiter`, `Bulkhead`, `TimeLimiter`, `Fallback` — each a plain
`call(callable): mixed` decorator, built once per named instance from `firefly.resilience.*` by a
config-driven `ResilienceRegistry`.

## The programmatic model

Inject `ResilienceRegistry` and pull a named, typed pattern instance from it:

```php
final class PaymentGateway
{
    public function __construct(private readonly ResilienceRegistry $registry) {}

    public function charge(Order $order): Receipt
    {
        return $this->registry->circuitBreaker('payments')->call(
            fn (): Receipt => $this->client->charge($order),
        );
    }
}
```

Each accessor (`retry()`, `circuitBreaker()`, `rateLimiter()`, `bulkhead()`, `timeLimiter()`) takes the
instance *name* and memoizes one built instance per name for the registry's lifetime. Naming an instance
that has no configured section still works — every pattern has sane defaults — but naming an instance under
a pattern that has *other* named entries and getting the name wrong throws a `ConfigurationException` that
lists the instances that **are** configured, e.g. `No resilience [retry] instance named [missing]. Available:
default, aggressive.`

`Fallback` is the one pattern **not** served by the registry: it holds no cross-call configuration (just a
recovery value/closure and an exception list), so it is constructed directly at the call site — see
[Fallback](#fallback) below.

### Config layout

Every registry-backed pattern reads a `firefly.resilience.<pattern>.<name>.*` section, where `<pattern>` is
one of `retry`, `circuit-breaker`, `rate-limiter`, `bulkhead`, `time-limiter` (kebab-case) and `<name>` is
whatever name your code passes to the accessor:

```php
// config/firefly.php
return [
    'resilience' => [
        'retry' => [
            'default' => ['max-attempts' => 3],
            'aggressive' => ['max-attempts' => 5, 'wait-duration' => '250ms', 'backoff-multiplier' => 2.0],
        ],
        'circuit-breaker' => [
            'payments' => ['failure-threshold' => 5, 'wait-duration-in-open' => '30s'],
        ],
        'rate-limiter' => [
            'api' => ['max-tokens' => 10, 'refill-rate' => 10.0, 'timeout' => '100ms'],
        ],
        'bulkhead' => [
            'db' => ['max-concurrent' => 20, 'max-wait' => '50ms'],
        ],
        'time-limiter' => [
            'payments' => ['timeout' => '2s'],
        ],
    ],
];
```

Duration-shaped keys (`wait-duration`, `max-wait`, `wait-duration-in-open`, `timeout`, …) accept either a
bare number of seconds or a `Firefly\Resilience\Duration`-parsed string: `250ms`, `30s`, `5m`, `1h`. `Duration`
is also reused by `firefly/scheduling` for `lock-ttl` and `fixedRate`/`fixedDelay`.

## The six patterns

### Retry

Re-invokes the callable up to `max-attempts` times while it throws a `retry-on` error, backing off
`wait-duration * backoff-multiplier^(attempt-1)` seconds between attempts (capped at `max-wait`, plus up to
`jitter` extra seconds). Stateless — a `Retry` holds no cross-request state, so it is fine to construct
directly (`new Retry(...)`) as a decorator too.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `max-attempts` | int | `3` | Total attempts, including the first. |
| `wait-duration` | duration | `0` | Base wait between attempts (`0` = no wait). |
| `backoff-multiplier` | float | `1.0` | Exponential multiplier applied per attempt. |
| `max-wait` | duration\|null | `null` | Caps the computed wait (`null` = uncapped). |
| `jitter` | float (seconds) | `0.0` | Extra random `[0, jitter)` seconds added to each wait. |
| `retry-on` | list\<class-string\<Throwable\>\> | `[Throwable::class]` | Only these (or subclasses) are retried; anything else rethrows immediately. |

Exhausting `max-attempts` rethrows the last exception unchanged — `Retry` never wraps it.

### CircuitBreaker

A CLOSED/OPEN/HALF_OPEN breaker over a bounded window of recent outcomes. CLOSED trips to OPEN once the
window accumulates `failure-threshold` failures (or, when `failure-rate-threshold` is set, once a *full*
window's failure ratio reaches it). OPEN rejects every call with `CircuitBreakerOpenException`
(`Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException`) until `wait-duration-in-open` has
elapsed, then admits up to `half-open-max-calls` probe calls; a probe success closes the breaker fresh, a
probe failure re-opens it.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `failure-threshold` | int | `5` | Failures within the window before tripping (ignored when `failure-rate-threshold` is set). |
| `failure-rate-threshold` | float\|null | `null` | Failure ratio (0..1) over a *full* window that trips instead of a raw count. |
| `window-size` | int | `10` | Size of the sliding outcome window. |
| `wait-duration-in-open` | duration | `30s` | How long OPEN rejects before allowing a HALF_OPEN probe. |
| `half-open-max-calls` | int | `1` | Probe calls admitted per HALF_OPEN episode. |
| `record-on` | list\<class-string\<Throwable\>\> | `[Throwable::class]` | Only these exceptions count as failures; anything else propagates without affecting the breaker's state. |

### RateLimiter

A token-bucket limiter: a bucket of `max-tokens` refills continuously at `refill-rate` tokens/second and
each call consumes one token. When the bucket is empty, `timeout` briefly polls for a refill before giving
up; `timeout: 0` (the default) fails fast. Rejection throws
`Firefly\Kernel\Exception\Infrastructure\RateLimitExceededException`. `tryAcquire(): bool` is available for
callers that want to check admission without invoking a callable.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `max-tokens` | int | `10` | Bucket capacity. |
| `refill-rate` | float | `10.0` | Tokens added per second (capped at `max-tokens`). |
| `timeout` | duration | `0` | How long to poll for a token before rejecting (`0` = fail fast). |

### Bulkhead

A concurrency semaphore: `acquire()` (called by `call()` on entry, released in `finally`) atomically
increments a shared permit counter and backs off immediately if it exceeds `max-concurrent`, so two racing
callers can never both slip past the limit. `max-wait` briefly polls for a freed permit before rejecting with
`Firefly\Resilience\Exception\BulkheadFullException` (a `firefly/resilience` infrastructure exception — the
kernel package is frozen, so this one exception lives in `firefly/resilience` itself rather than the kernel;
the other five patterns reuse shipped kernel exceptions verbatim).

| Key | Type | Default | Meaning |
|---|---|---|---|
| `max-concurrent` | int | `10` | Concurrent permits allowed. |
| `max-wait` | duration | `0` | How long to poll for a freed permit before rejecting (`0` = fail fast). |

### TimeLimiter

Bounds how long a call may run before it is considered timed out, throwing
`Firefly\Kernel\Exception\Infrastructure\TimeoutException`. See [Known-latent](#known-latent) below — its
actual preemption behaviour depends on whether the PHP process has `pcntl`.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `timeout` | duration | `30s` | The deadline. |

### Fallback

Not registry-driven: constructed directly with the recovery value (or a `Closure` given the caught
exception) and the exception list to catch, at the call site:

```php
$result = (new Fallback(fallback: fn (Throwable $e) => Receipt::declined($e), on: [PaymentGatewayException::class]))
    ->call(fn (): Receipt => $this->client->charge($order));
```

Any exception not in `on` (default `[Throwable::class]`, i.e. everything) rethrows unchanged.

## Composition and decorator ordering

Every pattern shares the same `call(callable): mixed` shape, so they compose by nesting closures — there is
no fluent chain in M7 (see [Known-latent](#known-latent)). A typical outside-in stacking order, from the
caller's perspective, mirrors Resilience4j's convention — outermost catches/observes the most, innermost sits
closest to the real call:

```php
$fallback = new Fallback(fn (Throwable $e) => Receipt::declined($e));

$result = $fallback->call(fn (): Receipt =>
    $registry->retry('payments')->call(fn (): Receipt =>
        $registry->circuitBreaker('payments')->call(fn (): Receipt =>
            $registry->bulkhead('payments')->call(fn (): Receipt =>
                $registry->timeLimiter('payments')->call(fn (): Receipt =>
                    $this->client->charge($order),
                ),
            ),
        ),
    ),
);
```

`Fallback` outermost means it can recover from *any* of the inner patterns' own exceptions (a tripped
breaker, an exhausted retry, a timed-out call). `Retry` wrapping `CircuitBreaker` means a retry attempt
that finds the breaker OPEN will retry against the still-open breaker rather than skip straight to failure —
order the two the other way around if you want retries to stop the instant the breaker trips. There is no
enforced ordering; compose the nesting that matches the semantics you want.

## Cache-backed state

`CircuitBreaker`, `RateLimiter`, and `Bulkhead` are **stateful across calls** — a breaker's outcome window, a
bucket's token count, a bulkhead's permit count — and PHP-FPM shares nothing between requests, so that state
cannot live on the pattern object. It lives behind the `Firefly\Resilience\Store\ResilienceStore` port
instead, keyed `firefly:resilience:<pattern>:<name>`, and every transition that reads-then-writes runs inside
`ResilienceStore::withLock()` so concurrent FPM workers never race a compare-and-set.

The default store, wired by `ResilienceAutoConfiguration` (`#[Order(1000)]`,
`#[ConditionalOnMissingBean(ResilienceStore::class)]`, always-on), is `CacheResilienceStore` over Laravel's
injected `Illuminate\Contracts\Cache\Repository`. `ResilienceRegistry` itself is likewise auto-configured
(`#[ConditionalOnMissingBean(ResilienceRegistry::class)]`) from `firefly.resilience.*`, so an empty
`resilience` config section still boots a working, empty registry — install the package and it's live with no
required configuration.

`Retry`, `TimeLimiter`, and `Fallback` are stateless and never touch the store.

## Known-latent

These are carried-forward, documented limitations of the M7 shipment — not bugs:

- **`TimeLimiter` cannot preempt a blocking call under PHP-FPM.** Preemption (actually interrupting a call
  mid-execution) requires `pcntl`'s `SIGALRM`, which is available on CLI/queue-worker/scheduled-task
  processes but **not** under PHP-FPM. Under FPM, `TimeLimiter` can only measure the elapsed wall-clock
  *after* the callable returns and throw `TimeoutException` post-hoc — a call that blocks longer than the
  timeout still blocks the FPM worker for its full duration before the exception is raised. On the `pcntl`
  path, `pcntl_alarm()`'s one-second granularity also means any `timeout` below `1s` is rounded up to `1s`
  (sub-second timeouts always use the post-hoc wall-clock path instead, on every runtime).
- **Programmatic only — no attribute interception yet.** `#[Retry]`/`#[CircuitBreaker]`-style method
  interception (annotate a method and have calls to it automatically wrapped) and a fluent decorator DSL both
  require AOP (method-call interception), which is **SP-5**, not M7. M7's resilience is exclusively the
  programmatic `$registry->pattern('name')->call(...)` model documented above.
- **Cache-backed state requires a persistent cache driver with atomic lock support.** `CacheResilienceStore`
  needs both persistence *and* `Illuminate\Contracts\Cache\LockProvider` support from the configured cache
  store to actually survive across FPM requests and stay race-free: `file`, `database`, and `redis` qualify.
  The `array` driver is **per-request** (each FPM request gets a fresh in-memory array), so breaker/limiter/
  bulkhead state resets every request — fine for tests and single-shot CLI runs, not for production
  cross-request behaviour. The `null` cache driver's locks are no-ops (`withLock()` degrades to running the
  callback without a critical section whenever the driver isn't a `LockProvider`), so it must not be used in
  production for these patterns either.
- **`CircuitBreaker` records have no idle TTL.** A breaker's cache record (state, window, open timestamp) is
  written with no expiry, so an idle key (a payment integration nobody calls for a month) lingers in the
  cache store indefinitely rather than being reclaimed. This is inert (no functional impact — the next call
  simply reads whatever state is there) but is a known, un-bounded cache-growth characteristic worth knowing
  about for capacity planning.

<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Store\ResilienceStore;
use Throwable;

/**
 * The config-driven named-instance registry (built once as a bean from firefly.resilience.*). Consumers
 * inject it and pull typed pattern instances by name; each accessor memoizes and, on an unknown name, throws
 * a ConfigurationException listing the configured instances. Instance config keys are kebab-case; the
 * cache-backed patterns share the injected ResilienceStore, keyed by "firefly:resilience:<pattern>:<name>".
 */
final class ResilienceRegistry
{
    /** @var array<string, Retry> */
    private array $retries = [];

    /** @var array<string, CircuitBreaker> */
    private array $circuitBreakers = [];

    /** @var array<string, RateLimiter> */
    private array $rateLimiters = [];

    /** @var array<string, Bulkhead> */
    private array $bulkheads = [];

    /** @var array<string, TimeLimiter> */
    private array $timeLimiters = [];

    /** @param  array<string, mixed>  $config  the `firefly.resilience` section (pattern => instances-map) */
    public function __construct(
        private readonly array $config,
        private readonly ResilienceStore $store,
    ) {}

    public static function fromConfig(Config $config, ResilienceStore $store): self
    {
        /** @var array<string, mixed> $section */
        $section = $config->array('firefly.resilience', []);

        return new self($section, $store);
    }

    public function retry(string $name): Retry
    {
        return $this->retries[$name] ??= new Retry(
            maxAttempts: $this->int($c = $this->instance('retry', $name), 'max-attempts', 3),
            waitDuration: $this->seconds($c, 'wait-duration', 0.0),
            backoffMultiplier: $this->float($c, 'backoff-multiplier', 1.0),
            maxWait: $this->nullableSeconds($c, 'max-wait'),
            jitter: $this->float($c, 'jitter', 0.0),
            retryOn: $this->classList($c, 'retry-on'),
        );
    }

    public function circuitBreaker(string $name): CircuitBreaker
    {
        return $this->circuitBreakers[$name] ??= new CircuitBreaker(
            key: 'firefly:resilience:circuit-breaker:'.$name,
            store: $this->store,
            failureThreshold: $this->int($c = $this->instance('circuit-breaker', $name), 'failure-threshold', 5),
            failureRateThreshold: $this->nullableFloat($c, 'failure-rate-threshold'),
            windowSize: $this->int($c, 'window-size', 10),
            waitDurationInOpen: $this->seconds($c, 'wait-duration-in-open', 30.0),
            halfOpenMaxCalls: $this->int($c, 'half-open-max-calls', 1),
            recordOn: $this->classList($c, 'record-on'),
        );
    }

    public function rateLimiter(string $name): RateLimiter
    {
        return $this->rateLimiters[$name] ??= new RateLimiter(
            key: 'firefly:resilience:rate-limiter:'.$name,
            store: $this->store,
            maxTokens: $this->int($c = $this->instance('rate-limiter', $name), 'max-tokens', 10),
            refillRate: $this->float($c, 'refill-rate', 10.0),
            timeout: $this->seconds($c, 'timeout', 0.0),
        );
    }

    public function bulkhead(string $name): Bulkhead
    {
        return $this->bulkheads[$name] ??= new Bulkhead(
            key: 'firefly:resilience:bulkhead:'.$name,
            store: $this->store,
            maxConcurrent: $this->int($c = $this->instance('bulkhead', $name), 'max-concurrent', 10),
            maxWait: $this->seconds($c, 'max-wait', 0.0),
        );
    }

    public function timeLimiter(string $name): TimeLimiter
    {
        return $this->timeLimiters[$name] ??= new TimeLimiter(
            timeout: $this->seconds($this->instance('time-limiter', $name), 'timeout', 30.0),
        );
    }

    /** @return array<string, mixed> */
    private function instance(string $pattern, string $name): array
    {
        $group = $this->config[$pattern] ?? null;
        $instances = is_array($group) ? $group : [];

        if (! array_key_exists($name, $instances)) {
            $available = array_keys($instances);
            $list = $available === [] ? '(none configured)' : implode(', ', array_map('strval', $available));

            throw new ConfigurationException("No resilience [{$pattern}] instance named [{$name}]. Available: {$list}.");
        }

        $config = $instances[$name];
        if (! is_array($config)) {
            return [];
        }

        $normalized = [];
        foreach ($config as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /** @param  array<string, mixed>  $c */
    private function int(array $c, string $key, int $default): int
    {
        $value = $c[$key] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /** @param  array<string, mixed>  $c */
    private function float(array $c, string $key, float $default): float
    {
        $value = $c[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param  array<string, mixed>  $c */
    private function nullableFloat(array $c, string $key): ?float
    {
        $value = $c[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param  array<string, mixed>  $c */
    private function seconds(array $c, string $key, float $default): float
    {
        $value = $c[$key] ?? null;
        if (is_numeric($value)) {
            return (float) $value;
        }

        return is_string($value) ? Duration::parse($value) : $default;
    }

    /** @param  array<string, mixed>  $c */
    private function nullableSeconds(array $c, string $key): ?float
    {
        $value = $c[$key] ?? null;
        if (is_numeric($value)) {
            return (float) $value;
        }

        return is_string($value) ? Duration::parse($value) : null;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return list<class-string<Throwable>>
     */
    private function classList(array $c, string $key): array
    {
        $value = $c[$key] ?? null;
        if (! is_array($value) || $value === []) {
            return [Throwable::class];
        }

        $classes = [];
        foreach ($value as $entry) {
            if (is_string($entry) && is_a($entry, Throwable::class, true)) {
                $classes[] = $entry;
            }
        }

        return $classes === [] ? [Throwable::class] : $classes;
    }
}

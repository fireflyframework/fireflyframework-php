<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Resilience\RateLimiter;
use Firefly\Resilience\Store\ResilienceStore;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Token\TokenEndpointRateLimiter;
use Illuminate\Config\Repository;

/**
 * A store that records the TTL every write was given, so a test can see the one thing the bucket's VALUE
 * never shows: whether the key this endpoint just created will ever be reclaimed.
 *
 * The recording array is taken by reference and bound in the constructor body rather than promoted — a
 * by-reference promoted property is 8.4 syntax, and this suite parses at the framework's 8.3 floor.
 *
 * @param  array<string, float|null>  $ttls
 */
function ttlRecordingStore(array &$ttls): ResilienceStore
{
    return new class($ttls) implements ResilienceStore
    {
        /** @var array<string, mixed> */
        private array $values = [];

        /** @var array<string, float|null> */
        public array $ttls;

        /** @param  array<string, float|null>  $ttls */
        public function __construct(array &$ttls)
        {
            $this->ttls = &$ttls;
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
        {
            $this->values[$key] = $value;
            $this->ttls[$key] = $ttlSeconds;
        }

        public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
        {
            if (array_key_exists($key, $this->values)) {
                return false;
            }
            $this->put($key, $value, $ttlSeconds);

            return true;
        }

        public function increment(string $key, int $by = 1): int
        {
            $current = is_int($this->values[$key] ?? null) ? $this->values[$key] : 0;

            return $this->values[$key] = $current + $by;
        }

        public function decrement(string $key, int $by = 1): int
        {
            return $this->increment($key, -$by);
        }

        public function forget(string $key): void
        {
            unset($this->values[$key]);
        }

        public function withLock(string $key, float $ttlSeconds, callable $callback): mixed
        {
            return $callback();
        }
    };
}

/** @param  array<string, mixed>  $rateLimit  the `firefly.security.oauth2.server.rate_limit` block */
function rateLimitSettings(array $rateLimit): AuthorizationServerSettings
{
    return AuthorizationServerSettings::fromConfig(new Config(new Repository([
        'firefly' => ['security' => ['oauth2' => ['server' => ['issuer' => 'https://issuer.test', 'rate_limit' => $rateLimit]]]],
    ])));
}

/**
 * The buckets this endpoint writes are keyed by client id — or by IP ADDRESS when no client id was presented
 * — so their number grows with traffic and not with configuration. Before the idle TTL, every one of those
 * keys was written with no expiry at all: the highest-cardinality namespace in the framework was also the
 * only one that never forgot anything.
 */
it('writes every token-endpoint bucket with the thirty-day idle TTL by default', function () {
    $ttls = [];
    $limiter = new TokenEndpointRateLimiter(ttlRecordingStore($ttls), 2, 1.0);

    expect($limiter->acquire('192.0.2.7'))->toBeTrue()
        ->and($ttls)->toBe(['firefly:oauth2:token:'.hash('sha256', '192.0.2.7') => RateLimiter::DEFAULT_IDLE_TTL]);
});

it('honours a configured rate_limit.idle_ttl, and reads 0 as never-expire', function () {
    $ttls = [];
    $configured = new TokenEndpointRateLimiter(ttlRecordingStore($ttls), 2, 1.0, 3600.0);
    $configured->acquire('svc');

    $never = [];
    $unbounded = new TokenEndpointRateLimiter(ttlRecordingStore($never), 2, 1.0, 0.0);
    $unbounded->acquire('svc');

    expect($ttls['firefly:oauth2:token:'.hash('sha256', 'svc')])->toBe(3600.0)
        ->and($never['firefly:oauth2:token:'.hash('sha256', 'svc')])->toBeNull();
});

it('reads the idle TTL from firefly.security.oauth2.server.rate_limit.idle_ttl, defaulting to thirty days', function () {
    expect(rateLimitSettings([])->rateLimitIdleTtl)->toBe(RateLimiter::DEFAULT_IDLE_TTL)
        ->and(rateLimitSettings(['idle_ttl' => 3600])->rateLimitIdleTtl)->toBe(3600.0)
        ->and(rateLimitSettings(['idle_ttl' => '0'])->rateLimitIdleTtl)->toBe(0.0);
});

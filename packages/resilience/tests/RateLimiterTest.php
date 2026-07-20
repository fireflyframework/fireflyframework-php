<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\RateLimitExceededException;
use Firefly\Resilience\RateLimiter;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('throws RateLimitExceededException once the bucket is exhausted', function () {
    $limiter = new RateLimiter('rl:test', new InMemoryResilienceStore, maxTokens: 2, refillRate: 0.0);

    expect($limiter->call(fn (): string => 'a'))->toBe('a')
        ->and($limiter->call(fn (): string => 'b'))->toBe('b')
        ->and(fn () => $limiter->call(fn (): string => 'c'))->toThrow(RateLimitExceededException::class);
});

it('refills tokens over time so a later call succeeds', function () {
    $limiter = new RateLimiter('rl:refill', new InMemoryResilienceStore, maxTokens: 1, refillRate: 1000.0);

    expect($limiter->tryAcquire())->toBeTrue()
        ->and($limiter->tryAcquire())->toBeFalse();

    usleep(5000); // 5ms * 1000 tokens/s = 5 tokens refilled (capped at maxTokens 1)

    expect($limiter->tryAcquire())->toBeTrue();
});

it('shares one token bucket across separate store instances over one cache', function () {
    $cache = new Repository(new ArrayStore);
    $limiterA = new RateLimiter('rl:shared', new CacheResilienceStore($cache), maxTokens: 1, refillRate: 0.0);
    $limiterB = new RateLimiter('rl:shared', new CacheResilienceStore($cache), maxTokens: 1, refillRate: 0.0);

    expect($limiterA->tryAcquire())->toBeTrue()
        ->and($limiterB->tryAcquire())->toBeFalse(); // A drained the only token, in the shared cache
});

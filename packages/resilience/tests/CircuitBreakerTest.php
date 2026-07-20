<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('opens after the failure threshold and rejects further calls with CircuitBreakerOpenException', function () {
    $breaker = new CircuitBreaker('cb:test', new InMemoryResilienceStore, failureThreshold: 3, waitDurationInOpen: 30.0);
    $boom = fn () => throw new RuntimeException('down');

    foreach (range(1, 3) as $ignored) {
        try {
            $breaker->call($boom);
        } catch (RuntimeException) {
        }
    }

    expect($breaker->state())->toBe('open')
        ->and(fn () => $breaker->call(fn (): string => 'unreached'))->toThrow(CircuitBreakerOpenException::class);
});

it('keeps OPEN state in the shared cache across two separate store instances (cross-request survival)', function () {
    $cache = new Repository(new ArrayStore);
    $breakerA = new CircuitBreaker('cb:payments', new CacheResilienceStore($cache), failureThreshold: 2, waitDurationInOpen: 30.0);
    $breakerB = new CircuitBreaker('cb:payments', new CacheResilienceStore($cache), failureThreshold: 2, waitDurationInOpen: 30.0);

    $boom = fn () => throw new RuntimeException('charge failed');
    foreach (range(1, 2) as $ignored) {
        try {
            $breakerA->call($boom);
        } catch (RuntimeException) {
        }
    }

    // breakerB never saw a failure itself: it reads the tripped state purely from the shared cache.
    expect($breakerB->state())->toBe('open')
        ->and(fn () => $breakerB->call(fn (): string => 'unreached'))->toThrow(CircuitBreakerOpenException::class);
});

it('transitions to HALF_OPEN after the wait window and closes on a probe success', function () {
    $breaker = new CircuitBreaker('cb:half', new InMemoryResilienceStore, failureThreshold: 1, waitDurationInOpen: 0.0, halfOpenMaxCalls: 1);

    try {
        $breaker->call(fn () => throw new RuntimeException('x'));
    } catch (RuntimeException) {
    }
    expect($breaker->state())->toBe('open');

    // waitDurationInOpen 0 => the next admit flips OPEN->HALF_OPEN, the probe succeeds, the breaker closes.
    expect($breaker->call(fn (): string => 'ok'))->toBe('ok')
        ->and($breaker->state())->toBe('closed');
});

it('does not count an error outside record-on toward opening', function () {
    $breaker = new CircuitBreaker('cb:filtered', new InMemoryResilienceStore, failureThreshold: 1, recordOn: [LogicException::class]);

    try {
        $breaker->call(fn () => throw new RuntimeException('ignored'));
    } catch (RuntimeException) {
    }

    expect($breaker->state())->toBe('closed');
});

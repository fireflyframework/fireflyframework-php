<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Tests\Fixtures\ContendedResilienceStore;
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
    // waitDurationInOpen 0 means the open window is due the instant it starts, so the EFFECTIVE state is
    // already HALF_OPEN — state() reports what admit() would decide, not the last state written to the store.
    expect($breaker->state())->toBe('half_open');

    // The next admit flips OPEN->HALF_OPEN for real, the probe succeeds, and the breaker closes.
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

it('releases the HALF_OPEN probe permit when the probe throws an exception outside record-on', function () {
    // The permanent-wedge regression: call() only reached onFailure() for exceptions listed in record-on, so
    // a probe that threw anything else returned WITHOUT giving the half-open slot back. admit() then found
    // the probe budget exhausted on every subsequent call and rejected forever — the breaker could never
    // reach CLOSED or OPEN again, because OPEN->HALF_OPEN is the only transition that resets the budget and
    // the breaker was no longer OPEN.
    $breaker = new CircuitBreaker(
        'cb:wedge',
        new InMemoryResilienceStore,
        failureThreshold: 1,
        waitDurationInOpen: 0.0,
        halfOpenMaxCalls: 1,
        recordOn: [LogicException::class],
    );

    try {
        $breaker->call(fn () => throw new LogicException('recorded'));
    } catch (LogicException) {
    }
    expect($breaker->state())->toBe('half_open'); // waitDurationInOpen 0 => the probe window is already due

    // The probe throws something record-on ignores: neither success nor failure, but the slot must come back.
    try {
        $breaker->call(fn () => throw new RuntimeException('ignored by record-on'));
    } catch (RuntimeException) {
    }

    // Pre-fix this threw CircuitBreakerOpenException here, and for every call thereafter, forever.
    expect($breaker->call(fn (): string => 'ok'))->toBe('ok')
        ->and($breaker->state())->toBe('closed');
});

it('reclaims an abandoned HALF_OPEN probe permit once its deadline passes (a dead worker self-heals)', function () {
    // A worker that dies between admit() and the outcome write leaves a probe permit nobody will ever
    // return; there is no finally to run in a process that is gone. The permit therefore carries a deadline,
    // and admit() prunes expired permits before counting. This test writes the persisted record directly
    // because that is precisely what a half-dead worker leaves behind — the shape IS the contract here.
    $store = new InMemoryResilienceStore;
    $breaker = new CircuitBreaker('cb:dead', $store, waitDurationInOpen: 30.0, halfOpenMaxCalls: 1);

    $store->put('cb:dead', [
        'state' => 'half_open',
        'openedAt' => microtime(true),
        'halfOpenProbes' => [microtime(true) + 30.0], // still-live probe held by a worker that is running
        'outcomes' => [],
    ]);
    expect(fn () => $breaker->call(fn (): string => 'unreached'))->toThrow(CircuitBreakerOpenException::class);

    $store->put('cb:dead', [
        'state' => 'half_open',
        'openedAt' => microtime(true),
        'halfOpenProbes' => [microtime(true) - 0.001], // the holder died; the deadline has passed
        'outcomes' => [],
    ]);
    expect($breaker->call(fn (): string => 'ok'))->toBe('ok')
        ->and($breaker->state())->toBe('closed');
});

it('state() reports the EFFECTIVE state, flipping stale OPEN to HALF_OPEN once the wait window is due', function () {
    // The actuator/observability gauge reads state() without making a call, so a breaker whose open window
    // had already elapsed was reported as OPEN indefinitely — the dashboard showed a hard-down dependency
    // while the breaker was in fact ready to probe. state() must agree with what admit() would decide.
    $breaker = new CircuitBreaker('cb:effective', new InMemoryResilienceStore, failureThreshold: 1, waitDurationInOpen: 0.05);

    try {
        $breaker->call(fn () => throw new RuntimeException('down'));
    } catch (RuntimeException) {
    }

    expect($breaker->state())->toBe('open'); // inside the wait window: genuinely OPEN

    usleep(80_000);

    expect($breaker->state())->toBe('half_open'); // the window is due: admit() would let a probe through
});

it('does not open until minimum-number-of-calls outcomes have been recorded in the window', function () {
    // Without a minimum, a breaker with failure-threshold 1 trips on the very first failure a fresh window
    // ever sees — one blip on a low-traffic endpoint takes the dependency out for wait-duration-in-open.
    $breaker = new CircuitBreaker(
        'cb:minimum',
        new InMemoryResilienceStore,
        failureThreshold: 1,
        windowSize: 10,
        minimumNumberOfCalls: 3,
    );

    $boom = fn () => throw new RuntimeException('down');

    foreach ([1, 2] as $ignored) {
        try {
            $breaker->call($boom);
        } catch (RuntimeException) {
        }
        expect($breaker->state())->toBe('closed'); // fewer than 3 recorded calls: not enough evidence
    }

    try {
        $breaker->call($boom);
    } catch (RuntimeException) {
    }

    expect($breaker->state())->toBe('open');
});

it('never lets the probe-permit return replace the exception the caller actually threw', function () {
    // Returning the HALF_OPEN permit for an exception outside record-on is a store write, and the store is
    // fail-fast: a contended lock raises ServiceUnavailableException. That cleanup runs inside call()'s
    // catch block, so if it is allowed to throw it REPLACES the caller's exception — and an exception the
    // breaker was explicitly configured to ignore would come back as an infrastructure 503 the application
    // never raised. Pass-through for a non-recorded exception is the contract; the permit's deadline is the
    // backstop that makes abandoning the cleanup safe.
    $breaker = new CircuitBreaker(
        'cb:cleanup',
        new ContendedResilienceStore(failFromCall: 2), // admit() succeeds, releaseProbe() cannot
        waitDurationInOpen: 0.0,
        recordOn: [LogicException::class],
    );

    expect(fn () => $breaker->call(fn () => throw new RuntimeException('ignored by record-on')))
        ->toThrow(RuntimeException::class, 'ignored by record-on');
});

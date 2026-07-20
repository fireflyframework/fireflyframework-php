<?php

declare(strict_types=1);

use Firefly\Resilience\Retry;

it('retries a failing callable until it succeeds', function () {
    $calls = 0;
    $result = (new Retry(maxAttempts: 3))->call(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw new RuntimeException('transient');
        }

        return 'ok';
    });

    expect($result)->toBe('ok')->and($calls)->toBe(3);
});

it('rethrows the last error after exhausting attempts', function () {
    $calls = 0;
    $retry = new Retry(maxAttempts: 2);

    // A regular closure (not `fn () => ...`) is required here: an arrow function auto-captures
    // enclosing variables by value, which would shadow the inner `use (&$calls)` reference and
    // silently break the count assertion below.
    expect(function () use ($retry, &$calls) {
        $retry->call(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('always');
        });
    })->toThrow(RuntimeException::class, 'always');

    expect($calls)->toBe(2);
});

it('does not retry an error outside retry-on', function () {
    $calls = 0;
    $retry = new Retry(maxAttempts: 3, retryOn: [LogicException::class]);

    expect(function () use ($retry, &$calls) {
        $retry->call(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('nope');
        });
    })->toThrow(RuntimeException::class);

    expect($calls)->toBe(1);
});

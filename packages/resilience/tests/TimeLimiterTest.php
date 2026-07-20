<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Resilience\TimeLimiter;

it('passes a fast callable straight through', function () {
    expect((new TimeLimiter(0.05))->call(fn (): string => 'fast'))->toBe('fast');
});

it('throws TimeoutException (post-hoc) when a sub-second deadline is exceeded', function () {
    $limiter = new TimeLimiter(0.01);

    expect(fn () => $limiter->call(function (): string {
        usleep(30_000); // 30ms > 10ms deadline; sub-second => post-hoc wall-clock path, deterministic on CLI

        return 'slow';
    }))->toThrow(TimeoutException::class);
});

it('restores the caller\'s prior SIGALRM handler after a fast call (queue-worker safe)', function () {
    // A queued job runs inside a worker that has installed its own SIGALRM handler for --timeout.
    $sentinel = static function (): void {};
    pcntl_signal(SIGALRM, $sentinel);

    try {
        // >= 1s => pcntl path is taken; the fast callable finishes well under the deadline so no alarm fires.
        expect((new TimeLimiter(5.0))->call(fn (): string => 'ok'))->toBe('ok');

        // The sentinel must be RESTORED, not clobbered to SIG_DFL.
        expect(pcntl_signal_get_handler(SIGALRM))->toBe($sentinel);
    } finally {
        pcntl_signal(SIGALRM, SIG_DFL); // don't leak global signal state into other tests
    }
})->skip(! function_exists('pcntl_signal_get_handler'), 'requires pcntl');

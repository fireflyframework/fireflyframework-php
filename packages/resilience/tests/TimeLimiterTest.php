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

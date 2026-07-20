<?php

declare(strict_types=1);

use Firefly\Resilience\Fallback;

it('returns a static fallback value when the callable throws a matching error', function () {
    expect((new Fallback('cached'))->call(fn () => throw new RuntimeException('down')))->toBe('cached');
});

it('invokes a closure fallback with the caught exception', function () {
    $fb = new Fallback(fn (Throwable $e): string => 'recovered:'.$e->getMessage());

    expect($fb->call(fn () => throw new RuntimeException('boom')))->toBe('recovered:boom');
});

it('passes the result through when the callable succeeds', function () {
    expect((new Fallback('cached'))->call(fn (): string => 'live'))->toBe('live');
});

it('rethrows an error outside the on list', function () {
    $fb = new Fallback('cached', on: [LogicException::class]);

    expect(fn () => $fb->call(fn () => throw new RuntimeException('x')))->toThrow(RuntimeException::class);
});

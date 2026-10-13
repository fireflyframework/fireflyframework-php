<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\LeaseClock;

it('reads the clock in UTC and formats instants at millisecond precision', function () {
    $now = LeaseClock::now(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-12 12:00:00.123456', new DateTimeZone('+02:00')));

    expect(LeaseClock::format($now))->toBe('2026-10-12 10:00:00.123')
        ->and($now->getTimezone()->getName())->toBe('UTC');
});

it('moves an instant by fractional seconds', function () {
    $start = new DateTimeImmutable('2026-10-12 10:00:00.900', new DateTimeZone('UTC'));

    expect(LeaseClock::format(LeaseClock::plus($start, 0.25)))->toBe('2026-10-12 10:00:01.150')
        ->and(LeaseClock::format(LeaseClock::plus($start, 300)))->toBe('2026-10-12 10:05:00.900');
});

it('names a claimant after the host and the process, distinct per call', function () {
    $a = LeaseClock::claimant();

    expect($a)->toMatch('/^.+:\d+:[0-9a-f]{8}$/')
        ->and(strlen($a))->toBeLessThanOrEqual(100)
        ->and(LeaseClock::claimant())->not->toBe($a);
});

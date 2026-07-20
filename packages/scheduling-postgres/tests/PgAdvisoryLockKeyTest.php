<?php

declare(strict_types=1);

use Firefly\Scheduling\Postgres\PgAdvisoryLock;

it('maps a name to a deterministic signed 64-bit key', function () {
    $a = PgAdvisoryLock::key('App\\Jobs\\Reconcile::run');

    expect($a)->toBe(PgAdvisoryLock::key('App\\Jobs\\Reconcile::run'))     // deterministic
        ->and($a)->toBeGreaterThanOrEqual(PHP_INT_MIN)
        ->and($a)->toBeLessThanOrEqual(PHP_INT_MAX);
});

it('maps distinct names to distinct keys across a sample', function () {
    $names = ['a', 'b', 'App\\X::y', 'App\\X::z', 'Firefly\\Scheduling\\Tests\\Fixtures\\ReconcileJob::run'];
    $keys = array_map(PgAdvisoryLock::key(...), $names);

    expect(array_unique($keys))->toHaveCount(count($names));
});

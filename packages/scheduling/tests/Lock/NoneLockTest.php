<?php

declare(strict_types=1);

use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;

it('always acquires and treats release as a no-op', function () {
    $lock = new NoneLock;

    expect($lock)->toBeInstanceOf(DistributedLock::class)
        ->and($lock->tryAcquire('reports', 30.0))->toBeTrue()
        // The zero-coordination default never contends with itself: a second acquire of the SAME name is still true.
        ->and($lock->tryAcquire('reports', 30.0))->toBeTrue();

    $lock->release('reports'); // no-op, must not throw

    expect($lock->tryAcquire('reports', 30.0))->toBeTrue();
});

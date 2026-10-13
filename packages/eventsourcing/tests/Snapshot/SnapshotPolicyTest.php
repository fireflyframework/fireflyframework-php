<?php

declare(strict_types=1);

use Firefly\EventSourcing\EventSourcingSettings;
use Firefly\EventSourcing\Snapshot\SnapshotPolicy;

it('is due each time the version crosses a multiple of the threshold, and never when disabled or zero', function (): void {
    $policy = new SnapshotPolicy(true, 50, 3);

    expect($policy->due(0, 49))->toBeFalse()
        ->and($policy->due(48, 50))->toBeTrue()
        ->and($policy->due(49, 120))->toBeTrue()
        ->and($policy->due(50, 99))->toBeFalse()
        ->and((new SnapshotPolicy(false, 50, 3))->due(0, 100))->toBeFalse()
        ->and((new SnapshotPolicy(true, 0, 3))->due(0, 100))->toBeFalse()
        ->and(SnapshotPolicy::fromSettings(new EventSourcingSettings(snapshotThreshold: 10, snapshotKeepCount: 5)))->toEqual(new SnapshotPolicy(true, 10, 5));
});

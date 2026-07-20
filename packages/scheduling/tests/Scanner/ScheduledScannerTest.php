<?php

declare(strict_types=1);

use Firefly\Scheduling\Scanner\ScheduledScanner;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Tests\Fixtures\ScheduledJobs;

it('scans a #[Scheduled] method into a descriptor and derives the lock name from lock: true', function () {
    $descriptors = (new ScheduledScanner)->scan([
        'Firefly\\Scheduling\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    expect($descriptors)->toHaveCount(1); // only ScheduledJobs::reconcile carries #[Scheduled]

    $descriptor = $descriptors[0];

    expect($descriptor)->toBeInstanceOf(ScheduledDescriptor::class)
        ->and($descriptor->class)->toBe(ScheduledJobs::class)
        ->and($descriptor->method)->toBe('reconcile')
        ->and($descriptor->cron)->toBe('* * * * *')
        ->and($descriptor->fixedRate)->toBeNull()
        ->and($descriptor->fixedDelay)->toBeNull()
        ->and($descriptor->lockName)->toBe(ScheduledJobs::class.'::reconcile'); // lock: true => Class::method
});

it('returns an empty list for a directory with no #[Scheduled] methods', function () {
    expect((new ScheduledScanner)->scan(['Firefly\\Scheduling\\' => __DIR__.'/../../src']))->toBe([]);
});

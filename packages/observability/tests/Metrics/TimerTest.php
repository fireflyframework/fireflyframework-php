<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\Timer;

it('counts every sample into each bucket whose bound it does not exceed — cumulative, like Prometheus', function () {
    $timer = new Timer('http_server_requests_seconds', ['uri' => '/x'], [0.1, 0.5, 1.0]);

    $timer->record(0.05);
    $timer->record(0.1);   // on the bound: counted (le is "less than or equal")
    $timer->record(0.7);
    $timer->record(3.0);   // above every bound: only +Inf, i.e. count()

    expect($timer->hasBuckets())->toBeTrue()
        ->and($timer->buckets())->toBe([0.1, 0.5, 1.0])
        ->and($timer->bucketCounts())->toBe([
            ['le' => 0.1, 'count' => 2],
            ['le' => 0.5, 'count' => 2],
            ['le' => 1.0, 'count' => 3],
        ])
        ->and($timer->count())->toBe(4)
        ->and($timer->totalTimeSeconds())->toEqualWithDelta(3.85, 1e-9);
});

it('is a plain summary without buckets', function () {
    $timer = new Timer('t', []);
    $timer->record(1.0);

    expect($timer->hasBuckets())->toBeFalse()->and($timer->bucketCounts())->toBe([]);
});

it('rehydrates from aggregates without replaying samples', function () {
    $timer = Timer::fromAggregates('t', ['a' => 'b'], [0.1, 1.0], 5, 2.5, [1, 4]);

    expect($timer->count())->toBe(5)
        ->and($timer->totalTimeSeconds())->toBe(2.5)
        ->and($timer->bucketCounts())->toBe([['le' => 0.1, 'count' => 1], ['le' => 1.0, 'count' => 4]])
        ->and($timer->tags())->toBe(['a' => 'b']);
});

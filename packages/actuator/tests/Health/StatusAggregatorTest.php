<?php

declare(strict_types=1);

use Firefly\Actuator\Health\Status;
use Firefly\Actuator\Health\StatusAggregator;

it('returns the most severe status', function () {
    $aggregator = new StatusAggregator;

    expect($aggregator->aggregate([Status::Up, Status::Down, Status::Up]))->toBe(Status::Down)
        ->and($aggregator->aggregate([Status::Up, Status::Unknown]))->toBe(Status::Up)
        ->and($aggregator->aggregate([Status::OutOfService, Status::Up]))->toBe(Status::OutOfService);
});

it('treats an empty set as UP', function () {
    expect((new StatusAggregator)->aggregate([]))->toBe(Status::Up);
});

it('maps DOWN and OUT_OF_SERVICE to 503, others to 200', function () {
    expect(Status::Down->httpStatus())->toBe(503)
        ->and(Status::OutOfService->httpStatus())->toBe(503)
        ->and(Status::Up->httpStatus())->toBe(200)
        ->and(Status::Unknown->httpStatus())->toBe(200);
});

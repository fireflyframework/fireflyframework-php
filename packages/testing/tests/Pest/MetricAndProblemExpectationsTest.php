<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\SimpleMeterRegistry;
use PHPUnit\Framework\ExpectationFailedException;

it('asserts a recorded metric by name and tag subset', function () {
    $registry = new SimpleMeterRegistry;
    $registry->increment('orders.placed', ['region' => 'eu'], 1.0);

    // toHaveRecordedMetric() is registered at runtime by FireflyExpectations::register() (tests/Pest.php),
    // invisible to PHPStan's static reflection of the vendor Pest\Expectation class — same gotcha as
    // toHavePublished/toHaveHandledCommand/toBeUp (see RecordingEventPublisherTest.php). Kept as two
    // separate expect() statements (rather than a ->and()-chained one) because chaining off an
    // unresolvable-return-type expectation degrades the second call's error identifier from
    // method.notFound to method.nonObject, which a scoped ignore above can no longer target precisely.
    // @phpstan-ignore method.notFound
    expect($registry)->toHaveRecordedMetric('orders.placed');
    // @phpstan-ignore method.notFound
    expect($registry)->toHaveRecordedMetric('orders.placed', tags: ['region' => 'eu']);

    expect(function () use ($registry): void {
        // @phpstan-ignore method.notFound
        expect($registry)->toHaveRecordedMetric('nope');
    })->toThrow(ExpectationFailedException::class);
});

it('asserts an RFC-7807 problem-details array', function () {
    $problem = ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'x'];

    // @phpstan-ignore method.notFound
    expect($problem)->toBeProblemDetails(status: 404);

    expect(function () use ($problem): void {
        // @phpstan-ignore method.notFound
        expect($problem)->toBeProblemDetails(status: 500);
    })->toThrow(ExpectationFailedException::class);
});

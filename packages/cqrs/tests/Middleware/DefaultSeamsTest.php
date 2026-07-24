<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Cqrs\Security\QueryAuthorizer;

it('ships allow-all authorizers that never throw and satisfy both ports', function () {
    $authorizer = new AllowAllAuthorizer;

    expect($authorizer)->toBeInstanceOf(CommandAuthorizer::class)
        ->and($authorizer)->toBeInstanceOf(QueryAuthorizer::class);

    $authorizer->authorize(new stdClass); // reaching here (no throw) is the assertion
});

it('ships a no-op metrics recorder', function () {
    $metrics = new NoOpCqrsMetrics;
    expect($metrics)->toBeInstanceOf(CqrsMetrics::class);

    $metrics->recordCommandSuccess(new stdClass, 0.01);
    $metrics->recordCommandFailure(new stdClass, 0.02);
    $metrics->recordQuerySuccess(new stdClass, 0.03);
    $metrics->recordQueryFailure(new stdClass, 0.04); // reaching here (all no-ops, no throw) is the assertion
});

it('ships an always-miss query cache', function () {
    $cache = new NoOpQueryCache;
    expect($cache)->toBeInstanceOf(QueryCache::class)
        ->and($cache->get('any-key'))->toBeNull();

    $cache->put('k', 'v', 60);
    $cache->evict('k');

    expect($cache->get('k'))->toBeNull(); // still a miss — inert seam
});

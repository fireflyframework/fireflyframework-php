<?php

declare(strict_types=1);

use Firefly\Cqrs\Correlation\CorrelationContext;

it('generates a uuid4 correlation id on begin when none is active and returns null as the prior', function () {
    $context = new CorrelationContext;

    expect($context->currentId())->toBeNull();

    $prior = $context->begin();

    expect($prior)->toBeNull()
        ->and($context->currentId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('propagates an explicit id and restores the prior on end (no leak into the next command)', function () {
    $context = new CorrelationContext;

    $prior = $context->begin('outer-id');
    expect($prior)->toBeNull()
        ->and($context->currentId())->toBe('outer-id');

    // nested command reuses/overrides then restores
    $innerPrior = $context->begin('inner-id');
    expect($innerPrior)->toBe('outer-id')
        ->and($context->currentId())->toBe('inner-id');

    $context->end($innerPrior);
    expect($context->currentId())->toBe('outer-id');

    $context->end($prior);
    expect($context->currentId())->toBeNull();
});

it('reuses the active id when begin() is called with null inside an active correlation', function () {
    $context = new CorrelationContext;
    $context->begin('active');

    $prior = $context->begin(); // null id, but a correlation is active -> reuse it
    expect($prior)->toBe('active')
        ->and($context->currentId())->toBe('active');
});

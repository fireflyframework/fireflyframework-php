<?php

declare(strict_types=1);

use Firefly\Observability\Cqrs\MeterRegistryCqrsMetrics;
use Firefly\Observability\Metrics\SimpleMeterRegistry;

final class OpenAccountCommand {}

it('records command success + failure timers tagged by type and outcome', function () {
    $registry = new SimpleMeterRegistry;
    $metrics = new MeterRegistryCqrsMetrics($registry);

    $metrics->recordCommandSuccess(new OpenAccountCommand, 0.2);
    $metrics->recordCommandFailure(new OpenAccountCommand, 0.3);

    $ok = $registry->timer('cqrs_commands_seconds', ['type' => 'OpenAccountCommand', 'outcome' => 'success']);
    $ko = $registry->timer('cqrs_commands_seconds', ['type' => 'OpenAccountCommand', 'outcome' => 'failure']);

    expect($ok->count())->toBe(1)->and($ko->count())->toBe(1)->and($ko->totalTimeSeconds())->toBe(0.3);
});

it('records query timers', function () {
    $registry = new SimpleMeterRegistry;
    (new MeterRegistryCqrsMetrics($registry))->recordQuerySuccess(new OpenAccountCommand, 0.1);

    expect($registry->timer('cqrs_queries_seconds', ['type' => 'OpenAccountCommand', 'outcome' => 'success'])->count())->toBe(1);
});

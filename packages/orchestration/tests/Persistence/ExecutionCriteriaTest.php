<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionState;

it('matches on pattern, status and name, each optional', function (): void {
    $at = new DateTimeImmutable;
    $state = new ExecutionState('c', 'order', ExecutionPattern::Saga, ExecutionStatus::Failed, $at, $at);

    expect((new ExecutionCriteria)->matches($state))->toBeTrue()
        ->and((new ExecutionCriteria(pattern: ExecutionPattern::Saga, status: ExecutionStatus::Failed, name: 'order'))->matches($state))->toBeTrue()
        ->and((new ExecutionCriteria(pattern: ExecutionPattern::Tcc))->matches($state))->toBeFalse()
        ->and((new ExecutionCriteria(name: 'other'))->matches($state))->toBeFalse();
});

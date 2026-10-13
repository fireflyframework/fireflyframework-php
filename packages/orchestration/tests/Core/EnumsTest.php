<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\CompensationPolicy;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;

it('separates terminal, recoverable and suspendable execution statuses', function (): void {
    expect(ExecutionStatus::terminal())->toBe([
        ExecutionStatus::Completed, ExecutionStatus::Failed, ExecutionStatus::Cancelled,
        ExecutionStatus::TimedOut, ExecutionStatus::Confirmed, ExecutionStatus::Canceled,
    ])
        ->and(ExecutionStatus::Waiting->isTerminal())->toBeFalse()
        ->and(ExecutionStatus::Waiting->isRecoverable())->toBeFalse()
        ->and(ExecutionStatus::Confirming->isRecoverable())->toBeTrue()
        ->and(ExecutionStatus::Confirmed->isSuccess())->toBeTrue()
        ->and(ExecutionStatus::Waiting->canSuspend())->toBeTrue()
        ->and(ExecutionStatus::Completed->canSuspend())->toBeFalse()
        ->and(ExecutionStatus::Suspended->canResume())->toBeTrue();
});

it('knows which step statuses are settled and which are failures', function (): void {
    expect(StepStatus::Done->isSettled())->toBeTrue()
        ->and(StepStatus::Skipped->isSettled())->toBeTrue()
        ->and(StepStatus::Running->isSettled())->toBeFalse()
        ->and(StepStatus::TimedOut->isFailure())->toBeTrue()
        ->and(StepStatus::Compensated->isFailure())->toBeFalse();
});

it('reads compensation policies in kebab, snake and screaming spellings, with the jafly alias', function (string $value, CompensationPolicy $policy): void {
    expect(CompensationPolicy::fromConfig($value, 'firefly.orchestration.saga.compensation-policy'))->toBe($policy);
})->with([
    ['strict-sequential', CompensationPolicy::StrictSequential],
    ['GROUPED_PARALLEL', CompensationPolicy::GroupedParallel],
    ['retry_with_backoff', CompensationPolicy::RetryWithBackoff],
    ['circuit-breaker', CompensationPolicy::CircuitBreaker],
    ['best-effort', CompensationPolicy::BestEffort],
    ['BEST_EFFORT_PARALLEL', CompensationPolicy::BestEffort],
]);

it('refuses an unknown compensation policy, naming the key', function (): void {
    CompensationPolicy::fromConfig('optimistic', 'firefly.orchestration.saga.compensation-policy');
})->throws(ConfigurationException::class, 'firefly.orchestration.saga.compensation-policy');

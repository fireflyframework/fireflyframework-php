<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\Support\CrashScenarios;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\TestEngines;

/**
 * @param  array<string, mixed>  $recovery
 * @return array{0: TestEngines, 1: string}
 */
function orchestrationCrashed(string $scenario, string $correlationId, array $recovery): array
{
    $base = sys_get_temp_dir().'/firefly-orchestration-crash-'.bin2hex(random_bytes(6));
    $database = $base.'.sqlite';
    $marks = $base.'.marks';
    touch($database);
    IntegrationDatabase::fresh(IntegrationDatabase::sqlite($database));

    expect(CrashScenarios::crash("sqlite:{$database}", $scenario, $correlationId, $marks))->toBe(70);

    $connection = IntegrationDatabase::sqlite($database);
    $engines = TestEngines::create(['recovery' => $recovery], new DatabaseExecutionPersistenceProvider($connection), new DatabaseDeadLetterStore($connection));
    CrashScenarios::register($engines, $marks, crash: false);

    return [$engines, $marks];
}

it('leaves a killed saga RUNNING mid-step, and resume finishes it without repeating the settled step', function (): void {
    [$engines, $marks] = orchestrationCrashed('saga', 'crash-1', ['action' => 'resume', 'stale-threshold' => '1m']);
    $crashed = $engines->persistence->findById('crash-1');
    $engines->clock->advance(120.0);

    $report = $engines->recovery->recover();

    expect($crashed?->status)->toBe(ExecutionStatus::Running)
        ->and($crashed?->stepStatus('reserve'))->toBe(StepStatus::Done)
        ->and($crashed?->stepStatus('charge'))->toBe(StepStatus::Running)
        ->and($report->stale)->toBe(['crash-1'])
        ->and($report->resumed)->toBe(['crash-1'])
        ->and(CrashScenarios::marks($marks))->toBe(['reserve', 'charge'])
        ->and($engines->persistence->findById('crash-1')?->status)->toBe(ExecutionStatus::Completed);
});

it('compensates a killed saga when the recovery action is compensate', function (): void {
    [$engines, $marks] = orchestrationCrashed('saga', 'crash-2', ['action' => 'compensate', 'stale-threshold' => '1m']);
    $engines->clock->advance(120.0);

    $report = $engines->recovery->recover();

    expect($report->compensated)->toBe(['crash-2'])
        ->and(CrashScenarios::marks($marks))->toBe(['reserve', 'release'])
        ->and($engines->persistence->findById('crash-2')?->status)->toBe(ExecutionStatus::Cancelled);
});

it('only reports a killed saga by default, and leaves a fresh one alone', function (): void {
    [$engines] = orchestrationCrashed('saga', 'crash-3', ['stale-threshold' => '1h']);

    $fresh = $engines->recovery->recover();
    $engines->clock->advance(7200.0);
    $stale = $engines->recovery->recover();

    expect($fresh->stale)->toBe([])
        ->and($stale->stale)->toBe(['crash-3'])
        ->and($stale->resumed)->toBe([])
        ->and($engines->persistence->findById('crash-3')?->status)->toBe(ExecutionStatus::Running)
        ->and(implode("\n", $engines->logger->messages('warning')))->toContain('recovery.action is report');
});

it('resumes a TCC killed in CONFIRMING and confirms only the participant that was interrupted', function (): void {
    [$engines, $marks] = orchestrationCrashed('tcc', 'crash-4', ['action' => 'resume', 'stale-threshold' => '1m']);
    $crashed = $engines->persistence->findById('crash-4');
    $engines->clock->advance(120.0);

    $engines->recovery->recover();

    expect($crashed?->status)->toBe(ExecutionStatus::Confirming)
        ->and($crashed?->stepStatus('debit'))->toBe(StepStatus::Confirmed)
        ->and(CrashScenarios::marks($marks))->toBe(['try debit', 'try credit', 'confirm debit', 'confirm credit'])
        ->and($engines->persistence->findById('crash-4')?->status)->toBe(ExecutionStatus::Confirmed);
});

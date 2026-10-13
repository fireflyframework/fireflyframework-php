<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Registry\SignalWait;
use Firefly\Orchestration\Workflow\Registry\TimerWait;

it('waits durably for a signal and hands its payload to the step', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('approval')
        ->step('submit')->handler(static fn (): string => 'submitted')->add()
        ->step('approve')->dependsOn('submit')->waitForSignal('approved')->handler(static function (mixed $input, ExecutionContext $ctx): string {
            $payload = $ctx->variable('signals.approved');

            return 'approved by '.(is_array($payload) && is_string($payload['by'] ?? null) ? $payload['by'] : '?');
        })->add()
        ->build());

    $waiting = $engines->workflows->start('approval', correlationId: 'a-1');
    $done = $engines->workflows->signal('a-1', 'approved', ['by' => 'grace']);

    expect($waiting->status)->toBe(ExecutionStatus::Waiting)
        ->and($waiting->waitingOn)->toBe('approve')
        ->and($waiting->stepStatus('approve'))->toBe(StepStatus::Waiting)
        ->and($done->status)->toBe(ExecutionStatus::Completed)
        ->and($done->stepResults['approve'] ?? null)->toBe('approved by grace')
        ->and($engines->events->count('onSignalDelivered'))->toBe(1)
        ->and(fn () => $engines->workflows->signal('a-1', 'approved'))->toThrow(InvalidExecutionStateException::class, 'accepts no signals');
});

it('buffers a signal that arrives before its step and fires timers from the tick', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('cooldown')
        ->step('pause')->waitForTimer(600_000, 'cool')->add()
        ->step('go')->dependsOn('pause')->waitForSignal('go')->handler(static fn (): string => 'went')->add()
        ->build());

    $engines->workflows->start('cooldown', correlationId: 'c-1');
    $stillWaiting = $engines->workflows->signal('c-1', 'go');
    $engines->clock->advance(599.0);
    $early = $engines->workflows->resumeDue();
    $engines->clock->advance(1.0);
    $resumed = $engines->workflows->resumeDue();

    expect($stillWaiting->status)->toBe(ExecutionStatus::Waiting)
        ->and($stillWaiting->waitingOn)->toBe('pause')
        ->and($early)->toBe([])
        ->and($resumed[0]->status ?? null)->toBe(ExecutionStatus::Completed)
        ->and($engines->events->count('onTimerFired'))->toBe(1);
});

it('fails a step whose signal did not arrive in time, and compensates', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('kyc')
        ->step('open')->handler(static fn (): string => 'account')->compensation(static function () use (&$log): void {
            $log[] = 'close account';
        })->add()
        ->step('documents')->dependsOn('open')->waitForSignal('documents', 1000)->add()
        ->build());

    $engines->workflows->start('kyc', correlationId: 'k-1');
    $engines->clock->advance(1.5);
    $engines->workflows->resumeDue();
    $state = $engines->workflows->find('k-1');

    expect($state?->status)->toBe(ExecutionStatus::Failed)
        ->and($state?->stepStatus('documents'))->toBe(StepStatus::TimedOut)
        ->and($state?->failureReason)->toContain('waited longer than 1000 ms for signal [documents]')
        ->and($log)->toBe(['close account']);
});

it('waits for all of a set, and for any of a set', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('all')
        ->step('both')->waitForAll([new SignalWait('legal'), new SignalWait('finance')])->handler(static fn (): string => 'signed off')->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('any')
        ->step('first')->waitForAny([new SignalWait('manual')], [new TimerWait('auto', 60_000)])->handler(static fn (mixed $input, ExecutionContext $ctx): string => 'by '.(is_string($ctx->variable('wait.trigger')) ? $ctx->variable('wait.trigger') : '?'))->add()->build());

    $engines->workflows->start('all', correlationId: 'all-1');
    $half = $engines->workflows->signal('all-1', 'legal');
    $full = $engines->workflows->signal('all-1', 'finance');
    $engines->workflows->start('any', correlationId: 'any-1');
    $engines->clock->advance(60.0);
    $byTimer = $engines->workflows->resumeDue()[0] ?? null;

    expect($half->status)->toBe(ExecutionStatus::Waiting)
        ->and($full->status)->toBe(ExecutionStatus::Completed)
        ->and($byTimer?->stepResults['first'] ?? null)->toBe('by auto');
});

it('keeps advancing the other due executions when one of them cannot be driven', function (): void {
    $timer = static fn (string $id) => WorkflowBuilder::workflow($id)
        ->step('pause')->waitForTimer(60_000)->add()
        ->step('go')->dependsOn('pause')->handler(static fn (): string => 'went')->add()
        ->build();
    $before = TestEngines::create();
    $before->workflowRegistry->register($timer('retired'));
    $before->workflowRegistry->register($timer('kept'));
    $before->workflows->start('retired', correlationId: 'a-retired');
    $before->workflows->start('kept', correlationId: 'b-kept');

    // A deploy drops [retired] while its execution still waits on a timer that is due first.
    $after = TestEngines::create(persistence: $before->persistence);
    $after->workflowRegistry->register($timer('kept'));
    $after->clock->advance(61.0);
    $advanced = $after->workflows->resumeDue();
    $warnings = $after->logger->messages('warning');

    expect(array_map(static fn ($state): string => $state->correlationId, $advanced))->toBe(['b-kept'])
        ->and($after->workflows->find('b-kept')?->status)->toBe(ExecutionStatus::Completed)
        ->and($after->workflows->find('a-retired')?->status)->toBe(ExecutionStatus::Waiting)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toContain('a-retired');
});

it('backs off due executions it cannot drive, so a full batch of them does not starve a healthy one', function (): void {
    $timer = static fn (string $id) => WorkflowBuilder::workflow($id)
        ->step('pause')->waitForTimer(60_000)->add()
        ->step('go')->dependsOn('pause')->handler(static fn (): string => 'went')->add()
        ->build();
    $before = TestEngines::create(['backpressure' => ['batch-size' => 3]]);
    $before->workflowRegistry->register($timer('retired'));
    $before->workflowRegistry->register($timer('kept'));
    foreach (range(1, 5) as $n) {
        $before->workflows->start('retired', correlationId: "a-retired-{$n}");
    }
    $before->workflows->start('kept', correlationId: 'b-kept');

    // A deploy drops [retired]: five undrivable rows are due ahead of the healthy one, more than one batch.
    $after = TestEngines::create(['backpressure' => ['batch-size' => 3]], persistence: $before->persistence);
    $after->workflowRegistry->register($timer('kept'));
    $after->clock->advance(61.0);
    $advanced = $after->workflows->resumeDue();
    $retired = $after->workflows->find('a-retired-1');
    $warnings = count($after->logger->messages('warning'));
    $again = $after->workflows->resumeDue();

    expect(array_map(static fn ($state): string => $state->correlationId, $advanced))->toBe(['b-kept'])
        ->and($after->workflows->find('b-kept')?->status)->toBe(ExecutionStatus::Completed)
        ->and($retired?->status)->toBe(ExecutionStatus::Waiting)
        ->and($retired?->wakeAt)->toEqual($after->clock->now()->modify('+60 seconds'))
        ->and($warnings)->toBe(5)
        ->and($again)->toBe([])
        ->and(count($after->logger->messages('warning')))->toBe(5);
});

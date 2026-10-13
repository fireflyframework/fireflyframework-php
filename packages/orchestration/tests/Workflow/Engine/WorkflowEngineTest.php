<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Engine\ContinueAsNew;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

it('runs steps in layer order, skips a step whose condition is false and publishes output events', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $completed = [];
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('onboard')
        ->step('create')->handler(static function (array $input) use (&$log): array {
            $log[] = 'create';

            return ['userId' => 'u-'.(is_string($input['email'] ?? null) ? $input['email'] : '')];
        })->setVariable('user')->outputEventType('users.created')->add()
        ->step('vip')->dependsOn('create')->condition('input["tier"] == "gold"')->handler(static function () use (&$log): string {
            $log[] = 'vip';

            return 'vip';
        })->add()
        ->step('welcome')->dependsOn('create')->handler(static function (mixed $input, ExecutionContext $ctx) use (&$log): string {
            $user = $ctx->variable('user');
            $log[] = 'welcome '.(is_array($user) && is_string($user['userId'] ?? null) ? $user['userId'] : '?');

            return 'sent';
        })->add()
        ->onStepComplete(static function (ExecutionContext $ctx, mixed $result) use (&$completed): void {
            $completed[] = is_string($result) ? $result : 'array';
        })
        ->build());

    $state = $engines->workflows->start('onboard', ['email' => 'ada', 'tier' => 'silver'], correlationId: 'w-1');

    expect($state->status)->toBe(ExecutionStatus::Completed)
        ->and($log)->toBe(['create', 'welcome u-ada'])
        ->and($state->stepStatus('vip'))->toBe(StepStatus::Skipped)
        ->and($state->stepResults['welcome'] ?? null)->toBe('sent')
        ->and($state->report['status'] ?? null)->toBe('COMPLETED')
        ->and($completed)->toBe(['array', 'sent'])
        ->and(array_column($engines->eda->published, 'eventType'))->toBe(['users.created'])
        ->and($engines->workflows->find('w-1')?->status)->toBe(ExecutionStatus::Completed);
});

it('compensates completed compensatable steps in reverse when a step fails, and dead-letters it', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('provision')
        ->step('vm')->handler(static fn (): string => 'vm-1')->compensation(static function (mixed $result) use (&$log): void {
            $log[] = 'destroy '.(is_string($result) ? $result : '?');
        })->add()
        ->step('dns')->dependsOn('vm')->handler(static fn (): string => 'dns-1')->compensation(static function () use (&$log): void {
            $log[] = 'remove dns';
        })->add()
        ->step('tls')->dependsOn('dns')->handler(static fn (): never => throw new RuntimeException('acme down'))->add()
        ->build());

    $state = $engines->workflows->start('provision', correlationId: 'w-2');

    expect($state->status)->toBe(ExecutionStatus::Failed)
        ->and($state->failureReason)->toBe('acme down')
        ->and($log)->toBe(['remove dns', 'destroy vm-1'])
        ->and($state->stepStatus('vm'))->toBe(StepStatus::Compensated)
        ->and($engines->deadLetterStore->findByCorrelationId('w-2')[0]->stepId ?? null)->toBe('tls');
});

it('ends COMPLETED when an error callback suppresses the failure', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('soft')
        ->step('try')->handler(static fn (): never => throw new RuntimeException('nope'))->add()
        ->onError(static function (): void {}, suppressError: true, stepIds: ['try'])
        ->build());

    expect($engines->workflows->start('soft')->status)->toBe(ExecutionStatus::Completed);
});

it('runs an ASYNC workflow from the queue, and a duplicate delivery is a no-op', function (): void {
    $engines = TestEngines::create();
    $runs = 0;
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('report')->triggerMode(TriggerMode::Async)
        ->step('build')->handler(static function () use (&$runs): string {
            $runs++;

            return 'pdf';
        })->add()->build());

    $pending = $engines->workflows->start('report', correlationId: 'w-3');

    expect($pending->status)->toBe(ExecutionStatus::Pending)
        ->and($engines->dispatcher->dispatched)->toBe(['w-3']);

    $states = $engines->dispatcher->runAll($engines->workflows);

    expect($states[0]->status)->toBe(ExecutionStatus::Completed)
        ->and($engines->workflows->run('w-3')->status)->toBe(ExecutionStatus::Completed)
        ->and($runs)->toBe(1);
});

it('continues as new, carrying the parent link forward and pointing the old run at the new one', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('poll')
        ->step('tick')->handler(static function (array $input): mixed {
            $n = is_int($input['n'] ?? null) ? $input['n'] : 0;

            return $n < 2 ? new ContinueAsNew(['n' => $n + 1]) : "done at {$n}";
        })->add()->build());

    $last = $engines->workflows->start('poll', ['n' => 0], correlationId: 'w-4');
    $first = $engines->workflows->find('w-4');

    expect($last->status)->toBe(ExecutionStatus::Completed)
        ->and($last->stepResults['tick'] ?? null)->toBe('done at 2')
        ->and($first?->status)->toBe(ExecutionStatus::Completed)
        ->and(is_string($first?->variables['continuedAs'] ?? null))->toBeTrue()
        ->and($last->headers[WorkflowEngine::CONTINUED_FROM_HEADER] ?? null)->toBe('w-4')
        ->and($engines->events->count('onContinueAsNew'))->toBe(2);
});

it('times a workflow out at its deadline, waits included', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('deadline')->timeoutMs(1000)
        ->step('wait')->waitForSignal('never')->add()->build());

    $waiting = $engines->workflows->start('deadline', correlationId: 'w-5');
    $engines->clock->advance(2.0);
    $engines->workflows->resumeDue();

    expect($waiting->status)->toBe(ExecutionStatus::Waiting)
        ->and(ExecutionState::instant($waiting->wakeAt ?? new DateTimeImmutable))->toBe('2026-10-12T10:00:01.000000+00:00')
        ->and($engines->workflows->find('w-5')?->status)->toBe(ExecutionStatus::TimedOut);
});

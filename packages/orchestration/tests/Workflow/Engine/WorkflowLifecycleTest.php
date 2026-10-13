<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;

function orchestrationLifecycleEngines(): TestEngines
{
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('order')
        ->step('place')->handler(static function (array $input, ExecutionContext $ctx): string {
            $ctx->setSearchAttribute('customer', is_string($input['customer'] ?? null) ? $input['customer'] : '?');

            return 'placed';
        })->add()
        ->step('ship')->dependsOn('place')->waitForSignal('paid')->handler(static fn (): string => 'shipped')->add()
        ->query('progress', static function (ExecutionContext $ctx, mixed ...$arguments): string {
            $unit = is_string($arguments[0] ?? null) ? $arguments[0] : 'steps';

            return count($ctx->results())." {$unit}";
        })
        ->build());

    return $engines;
}

it('suspends and resumes a waiting workflow, signals included', function (): void {
    $engines = orchestrationLifecycleEngines();
    $engines->workflows->start('order', ['customer' => 'ada'], correlationId: 'o-1');

    $suspended = $engines->workflows->suspend('o-1', 'fraud review');
    $buffered = $engines->workflows->signal('o-1', 'paid');
    $resumed = $engines->workflows->resume('o-1');

    expect($suspended->status)->toBe(ExecutionStatus::Suspended)
        ->and($suspended->wakeAt)->toBeNull()
        ->and($buffered->status)->toBe(ExecutionStatus::Suspended)
        ->and($resumed->status)->toBe(ExecutionStatus::Completed)
        ->and($engines->events->hooks())->toContain('onWorkflowSuspended', 'onWorkflowResumed')
        ->and(fn () => $engines->workflows->resume('o-1'))->toThrow(InvalidExecutionStateException::class);
});

it('cancels a workflow and answers queries and search from its checkpoint', function (): void {
    $engines = orchestrationLifecycleEngines();
    $engines->workflows->start('order', ['customer' => 'ada'], correlationId: 'o-1');
    $engines->workflows->start('order', ['customer' => 'grace'], correlationId: 'o-2');
    $engines->workflows->upsertSearchAttributes('o-2', ['priority' => 'high']);

    $cancelled = $engines->workflows->cancel('o-1');

    expect($cancelled->status)->toBe(ExecutionStatus::Cancelled)
        ->and($engines->workflows->query('o-2', 'progress', ['results']))->toBe('1 results')
        ->and(array_map(static fn ($s): string => $s->correlationId, $engines->workflows->search(['customer' => 'grace', 'priority' => 'high'])))->toBe(['o-2'])
        ->and(array_map(static fn ($s): string => $s->correlationId, $engines->workflows->findByStatus(ExecutionStatus::Waiting)))->toBe(['o-2'])
        ->and(fn () => $engines->workflows->cancel('o-1'))->toThrow(InvalidExecutionStateException::class);
});

it('keeps the execution report of a finished workflow when its search attributes are upserted', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('one-step')
        ->step('only')->handler(static fn (): string => 'done')->add()
        ->build());

    $completed = $engines->workflows->start('one-step', correlationId: 'w-1');
    $upserted = $engines->workflows->upsertSearchAttributes('w-1', ['k' => 'v']);

    expect($completed->report)->not->toBeNull()
        ->and($upserted->report)->toBe($completed->report)
        ->and($engines->workflows->find('w-1')?->report)->toBe($completed->report)
        ->and($engines->workflows->find('w-1')?->searchAttributes)->toBe(['k' => 'v']);
});

it('keeps the deadline of a waiting workflow in its wake-up when its search attributes are upserted', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('bounded')->timeoutMs(5_000)
        ->step('wait')->waitForSignal('go')->add()
        ->build());

    $waiting = $engines->workflows->start('bounded', correlationId: 'w-2');
    $upserted = $engines->workflows->upsertSearchAttributes('w-2', ['k' => 'v']);

    expect($waiting->wakeAt)->not->toBeNull()
        ->and($upserted->wakeAt)->toEqual($waiting->wakeAt)
        ->and($engines->workflows->find('w-2')?->wakeAt)->toEqual($waiting->wakeAt);
});

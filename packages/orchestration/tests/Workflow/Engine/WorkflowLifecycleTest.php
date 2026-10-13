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

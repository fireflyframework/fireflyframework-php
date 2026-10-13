<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Workflow\Registry\ChildWorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\SignalWait;
use Firefly\Orchestration\Workflow\Registry\TimerWait;
use Firefly\Orchestration\Workflow\Registry\WaitDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowQueryDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;
use Firefly\Orchestration\Workflow\Registry\WorkflowStepDefinition;

function orchestrationApprovalDefinition(): WorkflowDefinition
{
    $method = static fn (string $name): MethodInvocation => new MethodInvocation('App\\Approval', $name);

    return new WorkflowDefinition(
        id: 'approval',
        steps: [
            'submit' => new WorkflowStepDefinition('submit', $method('submit'), outputEventType: 'approval.submitted', compensatable: true, compensation: $method('withdraw')),
            'approve' => new WorkflowStepDefinition('approve', $method('approve'), ['submit'], wait: WaitDefinition::any([new SignalWait('approved', 86_400_000)], [new TimerWait('escalate', 3_600_000)])),
            'archive' => new WorkflowStepDefinition('archive', $method('archive'), ['approve'], options: new StepOptions(retry: 2, backoffMs: 10), condition: 'variables["signals.approved"] != null'),
            'audit' => new WorkflowStepDefinition('audit', $method('audit'), ['submit'], child: new ChildWorkflowDefinition('audit-trail', true, 60_000)),
        ],
        name: 'Expense approval',
        version: '2.0',
        triggerMode: TriggerMode::Async,
        triggerEventType: 'expenses.submitted',
        timeoutMs: 604_800_000,
        queries: ['status' => new WorkflowQueryDefinition('status', $method('status'))],
    );
}

it('layers its steps and keeps waits, children, conditions and queries', function (): void {
    $workflow = orchestrationApprovalDefinition();

    expect($workflow->layers)->toBe([['submit'], ['approve', 'audit'], ['archive']])
        ->and($workflow->step('approve')->wait?->kind)->toBe(WaitDefinition::ANY)
        ->and($workflow->step('audit')->child?->workflowId)->toBe('audit-trail')
        ->and($workflow->query('status')->name)->toBe('status')
        ->and(fn () => $workflow->query('nope'))->toThrow(DefinitionNotFoundException::class, 'Workflow [approval] declares no query [nope]')
        ->and(WorkflowDefinition::fromArray($workflow->toArray()))->toEqual($workflow);
});

it('refuses a compensatable step without a compensation', function (): void {
    new WorkflowDefinition('w', ['a' => new WorkflowStepDefinition('a', new ClosureInvocation(static fn (): null => null), compensatable: true)]);
})->throws(TopologyValidationException::class, 'Workflow [w] step [a] is compensatable but declares no compensation');

it('registers each workflow once', function (): void {
    $registry = new WorkflowRegistry;
    $registry->register(orchestrationApprovalDefinition());

    expect($registry->get('approval')->version)->toBe('2.0')
        ->and($registry->ids())->toBe(['approval'])
        ->and(fn () => $registry->register(orchestrationApprovalDefinition()))->toThrow(TopologyValidationException::class, 'Workflow [approval] is defined twice')
        ->and(fn () => $registry->get('ghost'))->toThrow(DefinitionNotFoundException::class);
});

it('builds every wait kind', function (): void {
    expect(WaitDefinition::signal('go', 50))->toEqual(new WaitDefinition(WaitDefinition::SIGNAL, [new SignalWait('go', 50)]))
        ->and(WaitDefinition::timer(10, 't'))->toEqual(new WaitDefinition(WaitDefinition::TIMER, [], [new TimerWait('t', 10)]))
        ->and(WaitDefinition::fromArray(WaitDefinition::all([new SignalWait('a')], [new TimerWait('b', 5)])->toArray()))->toEqual(WaitDefinition::all([new SignalWait('a')], [new TimerWait('b', 5)]));
});

it('refuses a step filed under a key that is not its own id', function (): void {
    new WorkflowDefinition('w', ['a' => new WorkflowStepDefinition('b', new ClosureInvocation(static fn (): null => null))]);
})->throws(TopologyValidationException::class, 'Workflow [w]: step [b] is filed under key [a]; a step must be keyed by its own id.');

it('refuses a dependency on a step it does not declare', function (): void {
    new WorkflowDefinition('w', ['a' => new WorkflowStepDefinition('a', new ClosureInvocation(static fn (): null => null), ['ghost'])]);
})->throws(TopologyValidationException::class, 'Workflow [w]: step [a] depends on [ghost], which is not a step of it.');

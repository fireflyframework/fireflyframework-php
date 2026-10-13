<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Registry\ChildWorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\SignalWait;
use Firefly\Orchestration\Workflow\Registry\WaitDefinition;

it('builds a workflow whose steps inherit its retries unless they set their own', function (): void {
    $workflow = WorkflowBuilder::workflow('onboarding')
        ->name('Onboarding')->triggerMode(TriggerMode::Async)->triggerEventType('users.registered')->timeoutMs(60_000)->retries(2, 50)->publishEvents()
        ->step('create')->handler(static fn (array $input): string => 'account')->compensation(static fn (): null => null)->add()
        ->step('verify')->dependsOn('create')->waitForSignal('email-verified', 3_600_000)->add()
        ->step('welcome')->dependsOn('verify')->retries(0)->outputEventType('users.welcomed')->condition('input["send"] == true')->handler(static fn (): string => 'sent')->add()
        ->query('progress', static fn (ExecutionContext $ctx): int => count($ctx->results()))
        ->onComplete(static function (): void {})
        ->build();

    expect($workflow->triggerMode)->toBe(TriggerMode::Async)
        ->and($workflow->publishEvents)->toBeTrue()
        ->and($workflow->step('create')->options)->toEqual(new StepOptions(2, 50))
        ->and($workflow->step('create')->compensatable)->toBeTrue()
        ->and($workflow->step('welcome')->options)->toEqual(new StepOptions(0, 50))
        ->and($workflow->step('verify')->wait)->toEqual(new WaitDefinition(WaitDefinition::SIGNAL, [new SignalWait('email-verified', 3_600_000)]))
        ->and($workflow->layers)->toBe([['create'], ['verify'], ['welcome']])
        ->and(array_keys($workflow->queries))->toBe(['progress'])
        ->and($workflow->onComplete)->toHaveCount(1);
});

it('refuses a step id declared twice and a step with neither handler, wait nor child', function (): void {
    $builder = WorkflowBuilder::workflow('dup')->step('a')->handler(static fn (): null => null)->add();

    expect(fn () => $builder->step('a')->handler(static fn (): null => null)->add())
        ->toThrow(TopologyValidationException::class, 'Workflow [dup] declares step [a] twice.')
        ->and(fn () => WorkflowBuilder::workflow('bare')->step('x')->add())
        ->toThrow(TopologyValidationException::class, 'Workflow [bare] step [x] has no handler.');
});

it('passes the input through a wait-only or child-only step and names a default timer', function (): void {
    $workflow = WorkflowBuilder::workflow('pass')
        ->step('pause')->waitForTimer(500)->add()
        ->step('spawn')->dependsOn('pause')->childWorkflow('child', false, 1_000)->setVariable('childId')->add()
        ->build();

    $pause = $workflow->step('pause');
    $spawn = $workflow->step('spawn');

    expect($pause->wait)->toEqual(WaitDefinition::timer(500, 'pause:timer:0'))
        ->and($pause->invocation)->toBeInstanceOf(ClosureInvocation::class)
        ->and($pause->invocation instanceof ClosureInvocation ? ($pause->invocation->closure)(['k' => 1]) : null)->toBe(['k' => 1])
        ->and($spawn->child)->toEqual(new ChildWorkflowDefinition('child', false, 1_000))
        ->and($spawn->invocation->setVariable())->toBe('childId')
        ->and($spawn->compensatable)->toBeFalse();
});

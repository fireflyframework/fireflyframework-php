<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Argument\ParameterBinding;
use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Saga\Registry\SagaStepDefinition;
use Firefly\Orchestration\Saga\Registry\StepEventDefinition;

/** @param list<string> $dependsOn */
function orchestrationMethodSagaStep(string $method, array $dependsOn = [], ?string $compensate = null): SagaStepDefinition
{
    return new SagaStepDefinition(
        id: $method,
        invocation: new MethodInvocation('App\\OrderSaga', $method, [new ParameterBinding('input', ParameterBinding::IMPLICIT)]),
        dependsOn: $dependsOn,
        options: StepOptions::of(1, 10, 500, true, 0.25, 'order-{correlationId}'),
        compensation: $compensate === null ? null : new MethodInvocation('App\\OrderSaga', $compensate),
        compensationRetry: 2,
        compensationCritical: $compensate !== null,
        event: $method === 'charge' ? new StepEventDefinition('payments', 'payment.captured', 'orderId') : null,
    );
}

function orchestrationMethodSaga(): SagaDefinition
{
    $step = orchestrationMethodSagaStep(...);

    return new SagaDefinition(
        name: 'order',
        steps: ['reserve' => $step('reserve', [], 'release'), 'charge' => $step('charge', ['reserve'], 'refund'), 'notify' => $step('notify', ['reserve'])],
        triggerEventType: 'orders.placed',
        onComplete: [new CallbackDefinition(class: 'App\\OrderSaga', method: 'done', parameters: [CallbackDefinition::RESULT])],
    );
}

it('computes its layers at construction and keeps step order', function (): void {
    $saga = orchestrationMethodSaga();

    expect($saga->layers)->toBe([['reserve'], ['charge', 'notify']])
        ->and($saga->stepIds())->toBe(['reserve', 'charge', 'notify'])
        ->and($saga->step('charge')->compensationOptions())->toEqual(new StepOptions(2, 10, 500, true, 0.25))
        ->and(fn () => $saga->step('ghost'))->toThrow(DefinitionNotFoundException::class, 'Saga [order] has no step [ghost]');
});

it('round-trips a compiled definition through its array form', function (): void {
    $saga = orchestrationMethodSaga();

    expect(SagaDefinition::fromArray($saga->toArray()))->toEqual($saga);
});

it('refuses an invalid topology the moment it is built', function (): void {
    new SagaDefinition('loop', [
        'a' => new SagaStepDefinition('a', new ClosureInvocation(static fn (): null => null), ['b']),
        'b' => new SagaStepDefinition('b', new ClosureInvocation(static fn (): null => null), ['a']),
    ]);
})->throws(TopologyValidationException::class, 'Saga [loop]: the steps form a cycle');

it('registers each saga once and finds it by name', function (): void {
    $registry = new SagaRegistry;
    $registry->register(orchestrationMethodSaga());

    expect($registry->get('order')->name)->toBe('order')
        ->and($registry->has('order'))->toBeTrue()
        ->and($registry->names())->toBe(['order'])
        ->and(fn () => $registry->register(orchestrationMethodSaga()))->toThrow(TopologyValidationException::class, 'Saga [order] is defined twice')
        ->and(fn () => $registry->get('refund'))->toThrow(DefinitionNotFoundException::class, 'No saga [refund] is registered');
});

it('refuses a step filed under a key that is not its id', function (): void {
    new SagaDefinition('mismatch', [
        'a' => new SagaStepDefinition('b', new ClosureInvocation(static fn (): null => null)),
    ]);
})->throws(TopologyValidationException::class, 'Saga [mismatch]: step [b] is filed under key [a]');

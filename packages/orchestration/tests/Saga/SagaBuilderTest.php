<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;

it('builds a saga with every step option and callbacks', function (): void {
    $saga = SagaBuilder::saga('transfer')
        ->triggerEventType('transfers.requested')
        ->step('debit')
        ->handler(static fn (mixed $input): string => 'debited')
        ->compensation(static fn (mixed $result): string => 'credited back')
        ->retry(2)->backoffMs(5)->timeoutMs(100)->jitter(true, 0.2)->idempotencyKey('debit-{correlationId}')
        ->compensationRetry(1)->compensationBackoffMs(3)->compensationTimeoutMs(50)->compensationCritical()
        ->stepEvent('ledger', 'ledger.debited', 'accountId')->setVariable('debitRef')
        ->add()
        ->step('credit')->dependsOn('debit')->handler(static fn (): string => 'credited')->add()
        ->onComplete(static function (): void {})
        ->onError(static function (): void {}, suppressError: true, errorTypes: [RuntimeException::class])
        ->build();

    $debit = $saga->step('debit');

    expect($saga->layers)->toBe([['debit'], ['credit']])
        ->and($saga->triggerEventType)->toBe('transfers.requested')
        ->and($debit->options)->toEqual(new StepOptions(2, 5, 100, true, 0.2, 'debit-{correlationId}'))
        ->and($debit->compensationOptions())->toEqual(new StepOptions(1, 3, 50, true, 0.2))
        ->and($debit->compensationCritical)->toBeTrue()
        ->and($debit->event?->type)->toBe('ledger.debited')
        ->and($debit->invocation)->toBeInstanceOf(ClosureInvocation::class)
        ->and($debit->invocation->setVariable())->toBe('debitRef')
        ->and($saga->onComplete)->toHaveCount(1)
        ->and($saga->onError[0]->suppressError)->toBeTrue();
});

it('refuses a step without a handler', function (): void {
    SagaBuilder::saga('broken')->step('a')->add();
})->throws(TopologyValidationException::class, 'Saga [broken] step [a] has no handler');

it('refuses a step id declared twice instead of silently replacing the first', function (): void {
    SagaBuilder::saga('twice')
        ->step('a')->handler(static fn (): string => 'first')->add()
        ->step('a')->handler(static fn (): string => 'second')->add();
})->throws(TopologyValidationException::class, 'Saga [twice] declares step [a] twice');

<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Exception\BackpressureException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Saga\Engine\SagaResult;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\TestEngines;

/**
 * reserve → charge, reserve → notify. `charge` throws while $declined is true; every handler and
 * compensation appends to $log.
 *
 * @param  list<string>  $log
 */
function orchestrationOrderSaga(array &$log, bool &$declined): SagaDefinition
{
    return SagaBuilder::saga('order')
        ->step('reserve')
        ->handler(static function (array $input) use (&$log): array {
            $log[] = 'reserve';

            return ['hold' => 'h-'.(is_scalar($input['orderId'] ?? null) ? $input['orderId'] : '?')];
        })
        ->compensation(static function (mixed $result) use (&$log): string {
            $log[] = 'release '.(is_array($result) && is_string($result['hold'] ?? null) ? $result['hold'] : '?');

            return 'released';
        })
        ->add()
        ->step('charge')->dependsOn('reserve')
        ->handler(static function () use (&$log, &$declined): string {
            $log[] = 'charge';
            if ($declined) {
                throw new RuntimeException('card declined');
            }

            return 'paid';
        })
        ->compensation(static function () use (&$log): void {
            $log[] = 'refund';
        })
        ->stepEvent('payments', 'payment.captured', 'orderId')
        ->add()
        ->step('notify')->dependsOn('reserve')
        ->handler(static function () use (&$log): string {
            $log[] = 'notify';

            return 'sent';
        })
        ->add()
        ->build();
}

it('completes a saga, reports it, checkpoints it and calls #[OnSagaComplete]', function (): void {
    $engines = TestEngines::create(['events' => ['enabled' => true]]);
    $log = [];
    $declined = false;
    $completed = [];
    $saga = orchestrationOrderSaga($log, $declined);
    $saga = new SagaDefinition($saga->name, $saga->steps, onComplete: [CallbackDefinition::closure(static function (ExecutionContext $ctx, mixed $result) use (&$completed): void {
        $completed[] = $result instanceof SagaResult ? $result->correlationId : 'none';
    })]);

    $result = $engines->sagas->execute($saga, ['orderId' => 7], ['tenant' => 'acme'], 'c-1');

    expect($result->isSuccess())->toBeTrue()
        ->and($result->status)->toBe(ExecutionStatus::Completed)
        ->and($log)->toBe(['reserve', 'charge', 'notify'])
        ->and($result->stepResults())->toBe(['reserve' => ['hold' => 'h-7'], 'charge' => 'paid', 'notify' => 'sent'])
        ->and($result->report?->layers)->toBe([['reserve'], ['charge', 'notify']])
        ->and($result->headers)->toBe(['tenant' => 'acme'])
        ->and($engines->persistence->findById('c-1')?->status)->toBe(ExecutionStatus::Completed)
        ->and($engines->persistence->findById('c-1')?->report['status'] ?? null)->toBe('COMPLETED')
        ->and($completed)->toBe(['c-1'])
        ->and($engines->events->hooks())->toBe([
            'onStart', 'onStepStarted', 'onStepSuccess', 'onStepStarted', 'onStepSuccess', 'onStepStarted', 'onStepSuccess', 'onCompleted',
        ])
        ->and(array_column($engines->eda->published, 'eventType'))->toBe([
            'orchestration.execution_started', 'orchestration.step_completed', 'payment.captured',
            'orchestration.step_completed', 'orchestration.step_completed', 'orchestration.execution_completed',
        ])
        ->and($engines->eda->publishedOf('payment.captured')[0]['headers']['key'] ?? null)->toBe('7');
});

it('compensates the completed steps in reverse, dead-letters the failure and calls #[OnSagaError]', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $declined = true;
    $errors = [];
    $base = orchestrationOrderSaga($log, $declined);
    $saga = new SagaDefinition($base->name, $base->steps, onError: [CallbackDefinition::closure(static function (ExecutionContext $ctx, mixed $error) use (&$errors): void {
        $errors[] = $error instanceof Throwable ? $error->getMessage() : 'none';
    })]);

    $result = $engines->sagas->execute($saga, ['orderId' => 7], correlationId: 'c-2');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->error?->getMessage())->toBe('card declined')
        ->and($log)->toBe(['reserve', 'charge', 'release h-7'])
        ->and($result->failedSteps())->toBe(['charge'])
        ->and($result->compensatedSteps())->toBe(['reserve'])
        ->and($result->steps['notify']->status)->toBe(StepStatus::Pending)
        ->and($errors)->toBe(['card declined'])
        ->and($engines->persistence->findById('c-2')?->status)->toBe(ExecutionStatus::Failed)
        ->and($engines->persistence->findById('c-2')?->failureReason)->toBe('card declined')
        ->and($engines->deadLetterStore->findByCorrelationId('c-2')[0]->stepId ?? null)->toBe('charge')
        ->and($engines->deadLetterStore->findByCorrelationId('c-2')[0]->input ?? null)->toBe(['orderId' => 7]);
});

it('turns a suppressed error into COMPLETED, after compensating', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $declined = true;
    $base = orchestrationOrderSaga($log, $declined);
    $saga = new SagaDefinition($base->name, $base->steps, onError: [CallbackDefinition::closure(static function (): void {}, suppressError: true, errorTypes: [RuntimeException::class])]);

    $result = $engines->sagas->execute($saga, ['orderId' => 1]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->status)->toBe(ExecutionStatus::Completed)
        ->and($log)->toContain('release h-1');
});

it('retries a step and records its attempts', function (): void {
    $engines = TestEngines::create();
    $calls = 0;
    $saga = SagaBuilder::saga('flaky')->step('call')->retry(2)->backoffMs(1)->handler(static function () use (&$calls): string {
        if (++$calls < 3) {
            throw new RuntimeException('busy');
        }

        return 'ok';
    })->add()->build();

    $result = $engines->sagas->execute($saga);

    expect($result->isSuccess())->toBeTrue()->and($result->steps['call']->attempts)->toBe(3);
});

it('skips a step whose idempotency key was already recorded in this execution', function (): void {
    $engines = TestEngines::create();
    $charged = 0;
    $charge = static function () use (&$charged): string {
        $charged++;

        return 'charged';
    };
    $saga = SagaBuilder::saga('double')
        ->step('charge-a')->idempotencyKey('charge-{input.orderId}')->handler($charge)->add()
        ->step('charge-b')->dependsOn('charge-a')->idempotencyKey('charge-{input.orderId}')->handler($charge)->add()
        ->build();

    $result = $engines->sagas->execute($saga, ['orderId' => 9]);

    expect($charged)->toBe(1)
        ->and($result->steps['charge-b']->status)->toBe(StepStatus::Skipped)
        ->and($engines->events->count('onStepSkippedIdempotent'))->toBe(1);
});

it('stops at the saga-wide deadline and compensates', function (): void {
    $engines = TestEngines::create(['saga' => ['default-timeout' => '1s']]);
    $log = [];
    $saga = SagaBuilder::saga('slow')
        ->step('a')->handler(static function () use ($engines, &$log): string {
            $log[] = 'a';
            $engines->clock->advance(2.0);

            return 'a';
        })->compensation(static function () use (&$log): void {
            $log[] = 'undo a';
        })->add()
        ->step('b')->dependsOn('a')->handler(static function () use (&$log): string {
            $log[] = 'b';

            return 'b';
        })->add()
        ->build();

    $result = $engines->sagas->execute($saga);

    expect($log)->toBe(['a', 'undo a'])
        ->and($result->steps['b']->status)->toBe(StepStatus::TimedOut)
        ->and($result->error?->getMessage())->toContain('firefly.orchestration.saga.default-timeout');
});

it('refuses executions while sagas are disabled, and under back-pressure', function (): void {
    $saga = SagaBuilder::saga('any')->step('a')->handler(static fn (): string => 'a')->add()->build();
    $busy = TestEngines::create(['backpressure' => ['max-in-flight' => 1]]);
    $busy->persistence->save(ExecutionPersistenceContract::state('open'));

    expect(fn () => TestEngines::create(['saga' => ['enabled' => false]])->sagas->execute($saga))->toThrow(PatternDisabledException::class, 'firefly.orchestration.saga.enabled')
        ->and(fn () => $busy->sagas->execute($saga))->toThrow(BackpressureException::class);
});

it('starts a registered saga by name', function (): void {
    $engines = TestEngines::create();
    $engines->sagaRegistry->register(SagaBuilder::saga('hello')->step('greet')->handler(static fn (array $input): string => 'hello '.(is_string($input['who'] ?? null) ? $input['who'] : ''))->add()->build());

    expect($engines->sagas->execute('hello', ['who' => 'ada'])->resultOf('greet'))->toBe('hello ada');
});

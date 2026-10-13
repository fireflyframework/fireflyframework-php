<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\ExecutionNotFoundException;
use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Tests\Support\TestEngines;

/**
 * a → b → c, each step and each compensation appending to $log.
 *
 * @param  list<string>  $log
 */
function orchestrationChainSaga(array &$log): SagaDefinition
{
    $builder = SagaBuilder::saga('chain');
    $previous = null;
    foreach (['a', 'b', 'c'] as $id) {
        $step = $builder->step($id)
            ->handler(static function () use ($id, &$log): string {
                $log[] = $id;

                return "did {$id}";
            })
            ->compensation(static function (mixed $result) use ($id, &$log): void {
                $log[] = "undo {$id} (".(is_string($result) ? $result : '?').')';
            });
        $builder = ($previous === null ? $step : $step->dependsOn($previous))->add();
        $previous = $id;
    }

    return $builder->build();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function orchestrationCrashedChain(array $overrides): ExecutionState
{
    return ExecutionState::fromArray(array_merge([
        'correlationId' => 'c-9',
        'executionName' => 'chain',
        'pattern' => 'SAGA',
        'status' => 'RUNNING',
        'startedAt' => '2026-10-12T09:00:00.000000+00:00',
        'updatedAt' => '2026-10-12T09:00:01.000000+00:00',
        'input' => ['orderId' => 7],
        'topologyLayers' => [['a'], ['b'], ['c']],
    ], $overrides));
}

it('resumes a saga a crashed process left mid-step: settled steps are not run again', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->sagaRegistry->register(orchestrationChainSaga($log));
    $engines->persistence->save(orchestrationCrashedChain([
        'stepStatuses' => ['a' => 'DONE', 'b' => 'RUNNING'],
        'stepResults' => ['a' => 'did a'],
        'stepAttempts' => ['a' => 1, 'b' => 1],
        'completionOrder' => ['a'],
    ]));

    $result = $engines->sagas->resume('c-9');

    expect($result->isSuccess())->toBeTrue()
        ->and($log)->toBe(['b', 'c'])
        ->and($result->steps['b']->attempts)->toBe(2)
        ->and($result->resultOf('a'))->toBe('did a')
        ->and($engines->persistence->findById('c-9')?->status)->toBe(ExecutionStatus::Completed)
        ->and($engines->events->count('onStart'))->toBe(0);
});

it('continues an interrupted compensation without undoing a step twice', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->sagaRegistry->register(orchestrationChainSaga($log));
    $engines->persistence->save(orchestrationCrashedChain([
        'status' => 'COMPENSATING',
        'stepStatuses' => ['a' => 'DONE', 'b' => 'COMPENSATED', 'c' => 'FAILED'],
        'stepResults' => ['a' => 'did a', 'b' => 'did b'],
        'stepErrors' => ['c' => 'c broke'],
        'completionOrder' => ['a', 'b'],
    ]));

    $result = $engines->sagas->resume('c-9');

    expect($log)->toBe(['undo a (did a)'])
        ->and($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->error?->getMessage())->toBe('c broke')
        ->and($result->steps['a']->status)->toBe(StepStatus::Compensated);
});

it('compensates a completed saga on request and ends it CANCELLED', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->sagaRegistry->register(orchestrationChainSaga($log));
    $done = $engines->sagas->execute('chain', correlationId: 'c-1');

    $cancelled = $engines->sagas->compensate('c-1');

    expect($done->isSuccess())->toBeTrue()
        ->and($cancelled->status)->toBe(ExecutionStatus::Cancelled)
        ->and(array_slice($log, 3))->toBe(['undo c (did c)', 'undo b (did b)', 'undo a (did a)'])
        ->and($engines->persistence->findById('c-1')?->status)->toBe(ExecutionStatus::Cancelled);
});

it('refuses to resume a finished or an unknown execution', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->sagaRegistry->register(orchestrationChainSaga($log));
    $engines->sagas->execute('chain', correlationId: 'c-1');

    expect(fn () => $engines->sagas->resume('c-1'))->toThrow(InvalidExecutionStateException::class, 'COMPLETED')
        ->and(fn () => $engines->sagas->resume('nope'))->toThrow(ExecutionNotFoundException::class);
});

it('gives a resumed saga a fresh deadline budget measured from the resume, and still enforces it', function (): void {
    $engines = TestEngines::create(['saga' => ['default-timeout' => '1s']]);
    $log = [];
    $engines->sagaRegistry->register(SagaBuilder::saga('chain')
        ->step('a')->handler(static fn (): string => 'did a')->add()
        ->step('b')->dependsOn('a')->handler(static function () use ($engines, &$log): string {
            $log[] = 'b';
            $engines->clock->advance(2.0);

            return 'did b';
        })->add()
        ->step('c')->dependsOn('b')->handler(static function () use (&$log): string {
            $log[] = 'c';

            return 'did c';
        })->add()
        ->build());
    $engines->persistence->save(orchestrationCrashedChain([
        'stepStatuses' => ['a' => 'DONE'],
        'stepResults' => ['a' => 'did a'],
        'completionOrder' => ['a'],
    ]));

    $result = $engines->sagas->resume('c-9');

    expect($log)->toBe(['b'])
        ->and($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->steps['c']->status)->toBe(StepStatus::TimedOut)
        ->and($result->error?->getMessage())->toContain('firefly.orchestration.saga.default-timeout');
});

<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;

it('starts any pattern by name and reports a uniform outcome', function (): void {
    $engines = TestEngines::create();
    $engines->sagaRegistry->register(SagaBuilder::saga('s')->step('a')->handler(static fn (): string => 'a')->add()->build());
    $engines->tccRegistry->register(TccBuilder::tcc('t')->participant('p')->tryWith(static fn (): string => 'held')->confirmWith(static fn (): null => null)->cancelWith(static fn (): null => null)->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('w')->step('a')->handler(static fn (): string => 'a')->add()->build());

    $saga = $engines->orchestrator->start(ExecutionPattern::Saga, 's', ['x' => 1]);
    $tcc = $engines->orchestrator->start(ExecutionPattern::Tcc, 't');
    $workflow = $engines->orchestrator->start(ExecutionPattern::Workflow, 'w');

    expect([$saga->status, $tcc->status, $workflow->status])->toBe([ExecutionStatus::Completed, ExecutionStatus::Confirmed, ExecutionStatus::Completed])
        ->and($saga->success && $tcc->success && $workflow->success)->toBeTrue()
        ->and($saga->toArray())->toMatchArray(['pattern' => 'SAGA', 'name' => 's', 'status' => 'COMPLETED', 'success' => true])
        ->and($engines->orchestrator->find($tcc->correlationId)?->pattern)->toBe(ExecutionPattern::Tcc);
});

it('retries a dead letter by starting the definition again with its input, and counts the retries', function (): void {
    $engines = TestEngines::create();
    $switch = new ArrayObject(['fail' => true]);
    $engines->sagaRegistry->register(SagaBuilder::saga('flaky')->step('call')->handler(static function (array $input) use ($switch): string {
        if ($switch['fail']) {
            throw new RuntimeException('down');
        }

        return 'ok '.(is_int($input['id'] ?? null) ? $input['id'] : 0);
    })->add()->build());
    $engines->sagas->execute('flaky', ['id' => 5], ['tenant' => 'acme']);
    $first = $engines->deadLetterStore->findAll()[0];

    $again = $engines->retrier->retry($first->id);
    $second = $engines->deadLetterStore->findAll()[0];
    $switch['fail'] = false;
    $ok = $engines->retrier->retry($second->id);

    expect($again->success)->toBeFalse()
        ->and($engines->deadLetterStore->findById($first->id))->toBeNull()
        ->and($second->retryCount)->toBe(1)
        ->and($second->headers)->toBe(['tenant' => 'acme'])
        ->and($ok->success)->toBeTrue()
        ->and(data_get($ok->payload, 'steps.call.result'))->toBe('ok 5')
        ->and($engines->deadLetterStore->count())->toBe(0)
        ->and(DeadLetterEntry::RETRY_COUNT_HEADER)->toBe('x-firefly-dlq-retry-count');
});

it('keeps the dead letter when its retry cannot start', function (): void {
    $engines = TestEngines::create();
    $engines->sagaRegistry->register(SagaBuilder::saga('gone')->step('call')->handler(static fn (): never => throw new RuntimeException('down'))->add()->build());
    $engines->sagas->execute('gone', ['id' => 7]);
    $entry = $engines->deadLetterStore->findAll()[0];
    $withoutDefinition = TestEngines::create(deadLetters: $engines->deadLetterStore);

    expect(fn () => $withoutDefinition->retrier->retry($entry->id))->toThrow(DefinitionNotFoundException::class)
        ->and($engines->deadLetterStore->findById($entry->id)?->id)->toBe($entry->id);
});

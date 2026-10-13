<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Tcc\Registry\TccRegistry;
use Firefly\Orchestration\Tests\Support\RecordingLogger;
use Firefly\Orchestration\Validation\OrchestrationValidator;
use Firefly\Orchestration\Validation\ValidationIssue;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;

/** @return array{0: SagaRegistry, 1: TccRegistry, 2: WorkflowRegistry} */
function orchestrationRegistries(): array
{
    $sagas = new SagaRegistry;
    $sagas->register(SagaBuilder::saga('s')->step('a')->compensationCritical()->handler(static fn (): null => null)->add()->build());
    $workflows = new WorkflowRegistry;
    $workflows->register(WorkflowBuilder::workflow('parent')->step('spawn')->childWorkflow('ghost')->add()->build());
    $workflows->register(WorkflowBuilder::workflow('typo')->step('a')->condition('input[')->handler(static fn (): null => null)->add()->build());

    return [$sagas, new TccRegistry, $workflows];
}

it('reports unknown children and bad conditions as errors and critical steps without compensation as warnings', function (): void {
    [$sagas, $tccs, $workflows] = orchestrationRegistries();

    $issues = (new OrchestrationValidator(new ConditionEvaluator))->validate($sagas, $tccs, $workflows);

    expect(array_map(static fn (ValidationIssue $issue): string => $issue->severity, $issues))->toBe([ValidationIssue::WARNING, ValidationIssue::ERROR, ValidationIssue::ERROR])
        ->and($issues[0]->message)->toContain('Saga [s] step [a] is compensationCritical but has no compensation')
        ->and($issues[1]->message)->toContain('Workflow [parent] step [spawn] starts child workflow [ghost], which is not registered')
        ->and($issues[2]->message)->toContain('does not parse');
});

it('refuses on errors, and on warnings only when asked', function (): void {
    $logger = new RecordingLogger;
    $validator = new OrchestrationValidator(new ConditionEvaluator);
    $warning = [new ValidationIssue(ValidationIssue::WARNING, 'careful')];

    $validator->enforce($warning, false, $logger);

    expect($logger->messages('warning'))->toBe(['Orchestration validation: careful'])
        ->and(fn () => $validator->enforce($warning, true, $logger))->toThrow(TopologyValidationException::class, 'careful')
        ->and(fn () => $validator->enforce([new ValidationIssue(ValidationIssue::ERROR, 'broken')], false, $logger))->toThrow(TopologyValidationException::class, 'broken');
});

<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;

it('evaluates an expression over input, results, variables and headers', function (): void {
    $evaluator = new ConditionEvaluator;

    expect($evaluator->evaluate('input["amount"] > 100 and results["check"]["ok"]', [
        'input' => ['amount' => 150], 'results' => ['check' => ['ok' => true]], 'variables' => [], 'headers' => [],
    ]))->toBeTrue()
        ->and($evaluator->evaluate('variables["tier"] == "gold"', ['input' => [], 'results' => [], 'variables' => ['tier' => 'silver'], 'headers' => []]))->toBeFalse();
});

it('refuses at lint time an expression that does not parse or names an unknown variable', function (string $expression): void {
    (new ConditionEvaluator)->lint($expression, 'Workflow [w] step [s]');
})->with(['input[', 'order.total > 1'])->throws(TopologyValidationException::class, 'Workflow [w] step [s]');

it('wraps a runtime evaluation error', function (): void {
    (new ConditionEvaluator)->evaluate('input["a"]["b"] > 1', ['input' => ['a' => 3], 'results' => [], 'variables' => [], 'headers' => []]);
})->throws(OrchestrationException::class, 'could not be evaluated');

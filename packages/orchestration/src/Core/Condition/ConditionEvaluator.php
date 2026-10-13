<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Condition;

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Throwable;

/**
 * Workflow-step and composition conditions, in Symfony ExpressionLanguage (already a framework dependency).
 * An expression sees four variables — `input`, `results`, `variables`, `headers` — all arrays, so members
 * are read with subscripts: `input["amount"] > 100 and results["check"]["ok"]`.
 *
 * lint() runs at boot over every declared condition, with the four names, so a typo or an unknown variable
 * refuses the application instead of failing the first execution that reaches the step.
 */
final class ConditionEvaluator
{
    /** @var list<string> */
    public const array VARIABLES = ['input', 'results', 'variables', 'headers'];

    private readonly ExpressionLanguage $language;

    public function __construct(?ExpressionLanguage $language = null)
    {
        $this->language = $language ?? new ExpressionLanguage;
    }

    public function lint(string $expression, string $where): void
    {
        try {
            $this->language->lint($expression, self::VARIABLES);
        } catch (SyntaxError $error) {
            throw new TopologyValidationException("{$where}: condition [{$expression}] does not parse: {$error->getMessage()}", $error);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function evaluate(string $expression, array $values): bool
    {
        try {
            return (bool) $this->language->evaluate($expression, $values);
        } catch (Throwable $error) {
            throw new OrchestrationException("Condition [{$expression}] could not be evaluated: {$error->getMessage()}", 'ORCHESTRATION_CONDITION_FAILED', 500, $error);
        }
    }
}

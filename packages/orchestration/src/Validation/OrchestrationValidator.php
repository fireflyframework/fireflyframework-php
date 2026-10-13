<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Validation;

use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Tcc\Registry\TccRegistry;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;
use Psr\Log\LoggerInterface;

/**
 * The cross-definition checks a single definition cannot make about itself (jafly OrchestrationValidator),
 * run at boot over everything registered: a child workflow that no registry knows, a condition that does not
 * parse (builder definitions are not linted by the scanner), a compensationCritical saga step with nothing to
 * compensate. Topology itself — cycles, unknown dependsOn, duplicate ids, missing compensations — is refused
 * earlier, by each definition's constructor.
 */
final class OrchestrationValidator
{
    public function __construct(private readonly ConditionEvaluator $conditions) {}

    /** @return list<ValidationIssue> */
    public function validate(SagaRegistry $sagas, TccRegistry $tccs, WorkflowRegistry $workflows): array
    {
        $issues = [];

        foreach ($sagas->all() as $saga) {
            foreach ($saga->steps as $step) {
                if ($step->compensationCritical && $step->compensation === null) {
                    $issues[] = new ValidationIssue(ValidationIssue::WARNING, "Saga [{$saga->name}] step [{$step->id}] is compensationCritical but has no compensation.");
                }
            }
        }

        foreach ($workflows->all() as $workflow) {
            foreach ($workflow->steps as $step) {
                if ($step->child !== null && ! $workflows->has($step->child->workflowId)) {
                    $issues[] = new ValidationIssue(ValidationIssue::ERROR, "Workflow [{$workflow->id}] step [{$step->id}] starts child workflow [{$step->child->workflowId}], which is not registered.");
                }
                if ($step->condition !== '') {
                    try {
                        $this->conditions->lint($step->condition, "Workflow [{$workflow->id}] step [{$step->id}]");
                    } catch (TopologyValidationException $error) {
                        $issues[] = new ValidationIssue(ValidationIssue::ERROR, $error->getMessage());
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<ValidationIssue>  $issues
     */
    public function enforce(array $issues, bool $failOnWarning, LoggerInterface $logger): void
    {
        $refusals = [];
        foreach ($issues as $issue) {
            if ($issue->severity === ValidationIssue::ERROR || $failOnWarning) {
                $refusals[] = $issue->message;

                continue;
            }
            $logger->warning("Orchestration validation: {$issue->message}");
        }

        if ($refusals !== []) {
            throw new TopologyValidationException("Orchestration definitions are invalid:\n - ".implode("\n - ", $refusals));
        }
    }
}

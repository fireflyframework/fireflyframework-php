<?php

declare(strict_types=1);

namespace Firefly\Context\Condition;

/**
 * Records every (class, attribute-FQCN, ConditionOutcome) triple produced while evaluating
 * conditions across both passes. Queryable — feeds M12's /actuator/conditions endpoint.
 */
final class ConditionEvaluationReport
{
    /** @var list<array{class: string, attribute: string, outcome: ConditionOutcome}> */
    private array $entries = [];

    public function record(string $class, string $attribute, ConditionOutcome $outcome): void
    {
        $this->entries[] = ['class' => $class, 'attribute' => $attribute, 'outcome' => $outcome];
    }

    /** @return list<array{class: string, attribute: string, outcome: ConditionOutcome}> */
    public function matches(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['outcome']->matched,
        ));
    }

    /** @return list<array{class: string, attribute: string, outcome: ConditionOutcome}> */
    public function nonMatches(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => ! $entry['outcome']->matched,
        ));
    }

    /** @return list<array{class: string, attribute: string, outcome: ConditionOutcome}> */
    public function all(): array
    {
        return $this->entries;
    }
}

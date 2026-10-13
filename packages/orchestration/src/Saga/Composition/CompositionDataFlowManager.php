<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

/**
 * Builds a member's input (jafly CompositionDataFlowManager): the composition's input, overlaid by the
 * member's static input, overlaid by every data mapping that targets it. A source field is looked for inside
 * each array step result of the source member, then as a step id whose whole result is copied. Shared by the
 * TCC compositor.
 */
final class CompositionDataFlowManager
{
    /**
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $staticInput
     * @param  list<DataMapping>  $mappings
     * @param  array<string, array<string, mixed>>  $resultsByAlias  alias => step id => step result
     * @return array<string, mixed>
     */
    public function resolveInputs(array $global, array $staticInput, array $mappings, string $alias, array $resultsByAlias): array
    {
        $input = array_merge($global, $staticInput);

        foreach ($mappings as $mapping) {
            if ($mapping->targetSaga !== $alias || ! isset($resultsByAlias[$mapping->sourceSaga])) {
                continue;
            }

            $value = $this->extract($resultsByAlias[$mapping->sourceSaga], $mapping->sourceField);
            if ($value !== null) {
                $input[$mapping->targetField] = $value;
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $stepResults
     */
    private function extract(array $stepResults, string $field): mixed
    {
        foreach ($stepResults as $result) {
            if (is_array($result) && array_key_exists($field, $result)) {
                return $result[$field];
            }
        }

        return $stepResults[$field] ?? null;
    }
}

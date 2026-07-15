<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\BeanConditionAttribute;
use Firefly\Context\Definition\BeanDefinition;

/**
 * Evaluates every definition's REGISTRY-INDEPENDENT conditions — a condition attribute that is
 * NOT a bean-condition attribute (config presence, class presence, active profiles) — and removes
 * any definition whose conditions do not all match.
 *
 * Bean conditions (#[ConditionalOnBean], #[ConditionalOnMissingBean]) belong entirely to
 * ConditionPassTwoPass; this pass never evaluates them — see recordOutcomes()'s instanceof guard,
 * which is the phase partition made concrete.
 */
final class ConditionPassOnePass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::ConditionPassOne;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        foreach ($context->definitions->all() as $definition) {
            // ConditionEvaluator::matches() is the single source of truth for the keep/remove
            // decision AND for the user-component-bean-condition guard: it throws a
            // ConfigurationException for a bean condition on a DefinitionSource::User definition
            // regardless of which phase is running. Do not reimplement that check here.
            $keep = $context->conditions->matches($definition, beanPhase: false, registry: $context->definitions);

            $this->recordOutcomes($definition, $context);

            if (! $keep) {
                $context->definitions->remove($definition->class());
            }
        }
    }

    private function recordOutcomes(BeanDefinition $definition, BootContext $context): void
    {
        foreach ($definition->conditions as $condition) {
            if ($condition instanceof BeanConditionAttribute) {
                continue; // phase partition: bean conditions are ConditionPassTwoPass's job
            }

            $outcome = $context->conditions->evaluate($condition);
            $context->report->record($definition->class(), $condition::class, $outcome);
        }
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\BeanConditionAttribute;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;

/**
 * Spring's ConfigurationPhase.PARSE_CONFIGURATION, concretely: evaluates every
 * DefinitionSource::User definition's REGISTRY-INDEPENDENT conditions — a condition attribute
 * that is NOT a bean-condition attribute (config presence, class presence, active profiles) — and
 * removes any whose conditions do not all match.
 *
 * RUNS AT BootPhase::ConditionPassOne (400), strictly AFTER BootPhase::UserConfigurations (300)
 * adds every user definition to the registry — see BootPhase's own docblock for why this order is
 * load-bearing: a condition pass placed BEFORE its definitions exist evaluates against an
 * empty/partial registry and every condition vacuously "passes", which is exactly the bug this
 * ordering fixes. Do NOT move this pass earlier than UserConfigurations to "simplify" the pipeline.
 *
 * Scoped to DefinitionSource::User definitions ONLY — an AutoConfiguration definition (should one
 * ever exist in the registry this early, which the shipped pipeline never allows) is left
 * completely untouched: it is ConditionPassTwoPass's job, not this pass's, once it exists.
 *
 * Bean conditions (#[ConditionalOnBean], #[ConditionalOnMissingBean]) are illegal on a User
 * definition by design — ConditionEvaluator::matches() throws a ConfigurationException for one,
 * regardless of phase — so there is nothing else for THIS pass to do with a bean condition; see
 * recordOutcomes()'s instanceof guard, which keeps that partition concrete in the report too.
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
            if ($definition->source !== DefinitionSource::User) {
                continue; // not this pass's job — ConditionPassTwoPass owns AutoConfiguration definitions
            }

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

<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\BeanConditionAttribute;
use Firefly\Context\Condition\ConditionAttribute;
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
 * definition by design — ConditionEvaluator::matches()/matchesList() throw a ConfigurationException
 * for one, regardless of phase — so there is nothing else for THIS pass to do with a bean condition;
 * see recordOutcomes()'s instanceof guard, which keeps that partition concrete in the report too.
 *
 * METHOD-LEVEL conditions (BeanDefinition::$beanConditions, one #[Bean] method's own
 * #[ConditionalOn*] attributes) are handled here too, via applyBeanMethodConditions() — AFTER the
 * definition's own class-level keep/remove decision, and only when the definition itself survives
 * (a removed definition takes every one of its #[Bean] methods with it; there is nothing left to
 * filter). A method-level condition failing removes ONLY that #[Bean] method from the descriptor's
 * `beans` list — never the whole definition, and never any OTHER #[Bean] method on it.
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

                continue; // the whole definition is gone — every #[Bean] method went with it
            }

            $filtered = $this->applyBeanMethodConditions($definition, $context);
            if ($filtered !== $definition) {
                $context->definitions->remove($definition->class());
                $context->definitions->add($filtered);
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

    /**
     * Evaluates every #[Bean] method's own conditions (registry-independent only, beanPhase:
     * false — a method-level bean condition on a User definition is exactly as illegal as a
     * class-level one, and matchesList() enforces that unconditionally), records every outcome, and
     * returns a NEW BeanDefinition with any non-matching method removed from `beans` — or $definition
     * itself, UNCHANGED, when every method-level condition matches (or there are none).
     */
    private function applyBeanMethodConditions(BeanDefinition $definition, BootContext $context): BeanDefinition
    {
        $methodsToRemove = [];

        foreach ($definition->beanConditions as $method => $conditions) {
            $owner = $definition->class()."::{$method}()";

            $keep = $context->conditions->matchesList($conditions, $definition->source, $owner, beanPhase: false, registry: $context->definitions);

            /** @var ConditionAttribute $condition */
            foreach ($conditions as $condition) {
                if ($condition instanceof BeanConditionAttribute) {
                    continue; // phase partition: bean conditions are ConditionPassTwoPass's job
                }

                $outcome = $context->conditions->evaluate($condition);
                $context->report->record($owner, $condition::class, $outcome);
            }

            if (! $keep) {
                $methodsToRemove[] = $method;
            }
        }

        return $definition->withoutBeanMethods($methodsToRemove);
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;

/**
 * Spring's ConfigurationPhase.REGISTER_BEAN, concretely: evaluates EVERY condition — registry-
 * independent (config presence, class presence, active profiles) AND bean
 * (#[ConditionalOnBean]/#[ConditionalOnMissingBean]) — belonging to DefinitionSource::AutoConfiguration
 * definitions, against the now user-filtered registry, and removes any whose conditions do not all
 * match.
 *
 * RUNS AT BootPhase::ConditionPassTwo (600), strictly AFTER BootPhase::AutoConfigurations (500) adds
 * every auto-configuration definition to the registry, and after ConditionPassOnePass (400) has
 * already filtered every User definition — see BootPhase's own docblock for why this order is
 * load-bearing. Do NOT move this pass earlier than AutoConfigurations: a condition pass placed
 * before its definitions exist evaluates against an empty/partial registry and every condition
 * vacuously "passes" — which is precisely the latent bug this ordering fixes (an
 * AutoConfiguration's own #[ConditionalOnMissingBean] — the whole mechanism a starter uses to back
 * off — would never be evaluated).
 *
 * WHY *ALL* conditions, not just bean ones: an AutoConfiguration definition's registry-independent
 * conditions are STILL unevaluated at this point — they did not exist yet when ConditionPassOnePass
 * ran, so nothing has checked them. This pass is the first (and only) opportunity for both kinds.
 *
 * WHY ONLY AutoConfiguration definitions: a surviving User definition was already fully evaluated
 * by ConditionPassOnePass; re-evaluating it here would re-run and re-record conditions that already
 * have a decided outcome, corrupting the ConditionEvaluationReport with duplicate entries. Scoping
 * to DefinitionSource::AutoConfiguration keeps each definition evaluated by exactly one pass.
 *
 * SNAPSHOT STABILITY (load-bearing — see the M4 design decisions doc, §Conditions): removing
 * definition A partway through this pass can change whether definition B's
 * #[ConditionalOnMissingBean] matches, purely as a function of which one the registry happens to
 * iterate first. That would make the result of this pass depend on registry insertion/iteration
 * order — exactly the kind of thing a later maintainer "simplifies" into a filesystem-order-
 * dependent bug. To make the result order-INDEPENDENT, every definition's conditions are evaluated
 * against a FROZEN SNAPSHOT of the registry taken at pass entry, and every definition's fate is
 * decided against that same snapshot; only once every decision is made are the removals applied to
 * the live registry. Two definitions whose bean conditions reference each other therefore always
 * produce the SAME outcome, regardless of which was added to the registry first.
 */
final class ConditionPassTwoPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::ConditionPassTwo;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $snapshot = new BeanDefinitionRegistry;
        foreach ($context->definitions->all() as $definition) {
            $snapshot->add($definition);
        }

        /** @var list<string> $toRemove */
        $toRemove = [];
        foreach ($context->definitions->all() as $definition) {
            if ($definition->source !== DefinitionSource::AutoConfiguration) {
                continue; // not this pass's job — ConditionPassOnePass already decided User definitions
            }

            // ConditionEvaluator::matches() is the single source of truth for the keep/remove
            // decision. beanPhase: null means "evaluate every condition, of either kind" — correct
            // here because BOTH kinds of an AutoConfiguration definition's conditions are still
            // unevaluated at this point. Do not reimplement its logic here.
            $keep = $context->conditions->matches($definition, beanPhase: null, registry: $snapshot);

            $this->recordOutcomes($definition, $context, $snapshot);

            if (! $keep) {
                $toRemove[] = $definition->class();
            }
        }

        foreach ($toRemove as $class) {
            $context->definitions->remove($class);
        }
    }

    private function recordOutcomes(BeanDefinition $definition, BootContext $context, BeanDefinitionRegistry $snapshot): void
    {
        foreach ($definition->conditions as $condition) {
            $outcome = $context->conditions->evaluate($condition, $snapshot);
            $context->report->record($definition->class(), $condition::class, $outcome);
        }
    }
}

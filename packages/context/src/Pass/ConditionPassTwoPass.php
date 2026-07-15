<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\BeanConditionAttribute;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;

/**
 * Evaluates every definition's BEAN conditions (#[ConditionalOnBean], #[ConditionalOnMissingBean])
 * against the BeanDefinitionRegistry and removes any definition whose bean conditions do not all
 * match.
 *
 * SNAPSHOT STABILITY (load-bearing — see the M4 design decisions doc, §Conditions): removing
 * definition A partway through this pass can change whether definition B's
 * #[ConditionalOnMissingBean] matches, purely as a function of which one the registry happens to
 * iterate first. That would make the result of this pass depend on registry insertion/iteration
 * order — exactly the kind of thing a later maintainer "simplifies" into a filesystem-order-
 * dependent bug. To make the result order-INDEPENDENT, every definition's bean conditions are
 * evaluated against a FROZEN SNAPSHOT of the registry taken at pass entry, and every definition's
 * fate is decided against that same snapshot; only once every decision is made are the removals
 * applied to the live registry. Two definitions whose bean conditions reference each other
 * therefore always produce the SAME outcome, regardless of which was added to the registry first.
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
            // ConditionEvaluator::matches() is the single source of truth for the keep/remove
            // decision AND for the user-component-bean-condition guard: a bean condition on a
            // DefinitionSource::User definition throws a ConfigurationException here BY DESIGN —
            // do not catch or soften it, and do not reimplement its logic.
            $keep = $context->conditions->matches($definition, beanPhase: true, registry: $snapshot);

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
            if (! $condition instanceof BeanConditionAttribute) {
                continue; // phase partition: non-bean conditions were ConditionPassOnePass's job
            }

            $outcome = $context->conditions->evaluate($condition, $snapshot);
            $context->report->record($definition->class(), $condition::class, $outcome);
        }
    }
}

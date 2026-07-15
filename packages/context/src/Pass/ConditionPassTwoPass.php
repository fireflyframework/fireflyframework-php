<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;

/**
 * Spring's ConfigurationPhase.REGISTER_BEAN, concretely: evaluates EVERY condition — registry-
 * independent (config presence, class presence, active profiles) AND bean
 * (#[ConditionalOnBean]/#[ConditionalOnMissingBean]) — belonging to DefinitionSource::AutoConfiguration
 * definitions, and adds back only the ones whose conditions all match.
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
 * INCREMENTAL evaluation, NOT batch (this is a deliberate REVERSAL of an earlier "frozen snapshot"
 * design — read this before reintroducing it):
 *
 * An earlier version of this pass evaluated every AutoConfiguration definition's conditions against
 * a single snapshot of the registry taken at pass entry — the WHOLE registry, definitions-under-
 * evaluation included — then applied every removal at the end. That is Spring's
 * ConfigurationClassParser applied batch-wise, and it breaks in two ways a starter author actually
 * hits on day one:
 *
 *  1. SELF-SEEING: BeanDefinitionRegistry::containsType() does not exclude "the definition currently
 *     being evaluated" from its own scan. A definition whose own #[Bean] method (or `implements`
 *     clause) supplies type X, gated by #[ConditionalOnMissingBean(X::class)] — the canonical Spring
 *     Boot starter shape, e.g. `#[Bean] #[ConditionalOnMissingBean(CachePort::class)] public function
 *     defaultCache(): CachePort {...}` — sees ITS OWN contribution in the batch snapshot and always
 *     backs off from itself. The fallback never registers, ever, even with zero competing beans.
 *  2. MUTUAL BACK-OFF: two AutoConfigurations both supplying type X, both gated by
 *     #[ConditionalOnMissingBean(X::class)], both see "X present" in the same frozen snapshot (each
 *     sees the OTHER's contribution) and BOTH back off — the worst possible outcome: the user ends
 *     up with neither implementation and no error to explain why.
 *
 * Spring's actual model is INCREMENTAL: each auto-configuration is registered in a deterministic
 * order, and its conditions are evaluated against the registry AS IT STANDS immediately before that
 * definition itself is (re-)added — so a definition never sees its own contribution, and an already-
 * accepted earlier definition IS visible to a later one (first-registered, first-served: "first
 * wins" on a mutual #[ConditionalOnMissingBean] tie). This class now does the same:
 *
 *  1. Every DefinitionSource::AutoConfiguration definition is pulled OUT of the registry up front.
 *     The registry now holds exactly the surviving DefinitionSource::User definitions — nothing
 *     from this pass's own input set is present in it yet.
 *  2. The pulled-out definitions are sorted by the SAME deterministic total order FireflyKernel uses
 *     for boot passes: (#[Order] value, FQCN). #[Order] is read off the ComponentDescriptor captured
 *     at scan time (BeanDefinition::$descriptor->order) — NEVER off a resolved instance, because
 *     resolving an instance is exactly what condition evaluation must happen BEFORE. FQCN is the
 *     final tiebreak so the result does not depend on filesystem scan order (the same reasoning
 *     FireflyKernel::sortedPasses() documents for boot passes).
 *  3. Each definition, in that order, has ALL of its conditions evaluated against the registry AS IT
 *     CURRENTLY STANDS — which holds every surviving User definition plus every AutoConfiguration
 *     definition already accepted earlier in this same loop. A match adds the definition back to the
 *     registry (visible to every later definition in the loop); a non-match drops it (recorded in the
 *     ConditionEvaluationReport, never added back).
 *
 * Determinism now comes from the explicit (order, FQCN) sort, NOT from a frozen snapshot: swapping
 * the registry insertion order of two competing AutoConfiguration definitions cannot change the
 * result, because sorting happens before any of them are evaluated. This is strictly MORE correct
 * than the snapshot approach it replaces, not merely differently deterministic: it fixes
 * self-seeing (a definition is never present while its own conditions run) and turns "both removed"
 * into "first, by (order, FQCN), wins" — matching what Spring itself does and what every starter
 * author assumes #[ConditionalOnMissingBean] means.
 *
 * METHOD-LEVEL conditions (BeanDefinition::$beanConditions, one #[Bean] method's own
 * #[ConditionalOn*] attributes) are handled here too, via applyBeanMethodConditions() — evaluated
 * (and, if kept, added to the registry) ONLY once the candidate's own class-level conditions have
 * already been decided to keep it; a candidate removed at the class level takes every one of its
 * #[Bean] methods with it. Evaluating method-level conditions BEFORE add()ing the (possibly
 * bean-filtered) definition back to the registry preserves the same self-seeing avoidance the
 * class-level algorithm above depends on: a method's own #[Bean] contribution is never visible while
 * that SAME method's own condition is being decided, because the definition simply is not in the
 * registry yet. A method-level condition failing removes ONLY that #[Bean] method from the
 * descriptor's `beans` list — never the whole definition, and never any OTHER #[Bean] method on it.
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
        /** @var list<BeanDefinition> $candidates this pass's own input set, pulled out of the registry below */
        $candidates = [];
        foreach ($context->definitions->all() as $definition) {
            if ($definition->source !== DefinitionSource::AutoConfiguration) {
                continue; // not this pass's job — ConditionPassOnePass already decided User definitions
            }

            $candidates[] = $definition;
        }

        // Pull EVERY candidate out before evaluating ANY of them: this is what makes evaluation
        // incremental rather than batch — a candidate's own conditions can never see its own
        // contribution, because the candidate simply is not in the registry yet when they run.
        foreach ($candidates as $definition) {
            $context->definitions->remove($definition->class());
        }

        usort($candidates, self::compareByOrderThenClass(...));

        foreach ($candidates as $definition) {
            // ConditionEvaluator::matches() is the single source of truth for the keep/remove
            // decision. beanPhase: null means "evaluate every condition, of either kind" — correct
            // here because BOTH kinds of an AutoConfiguration definition's conditions are still
            // unevaluated at this point. Do not reimplement its logic here. $context->definitions is
            // the LIVE registry: at this point it holds every surviving User definition plus every
            // AutoConfiguration candidate already accepted earlier in this loop — never this
            // candidate itself.
            $keep = $context->conditions->matches($definition, beanPhase: null, registry: $context->definitions);

            $this->recordOutcomes($definition, $context);

            if ($keep) {
                $context->definitions->add($this->applyBeanMethodConditions($definition, $context));
            }
        }
    }

    private static function compareByOrderThenClass(BeanDefinition $a, BeanDefinition $b): int
    {
        $orderComparison = $a->descriptor->order <=> $b->descriptor->order;
        if ($orderComparison !== 0) {
            return $orderComparison;
        }

        return $a->class() <=> $b->class();
    }

    private function recordOutcomes(BeanDefinition $definition, BootContext $context): void
    {
        foreach ($definition->conditions as $condition) {
            $outcome = $context->conditions->evaluate($condition, $context->definitions);
            $context->report->record($definition->class(), $condition::class, $outcome);
        }
    }

    /**
     * Evaluates every #[Bean] method's own conditions — of EITHER kind, beanPhase: null, exactly
     * like this pass evaluates its class-level conditions, and for the same reason: an
     * AutoConfiguration's method-level conditions are still entirely unevaluated at this point —
     * records every outcome, and returns a NEW BeanDefinition with any non-matching method removed
     * from `beans`, or $definition itself, UNCHANGED, when every method-level condition matches (or
     * there are none).
     */
    private function applyBeanMethodConditions(BeanDefinition $definition, BootContext $context): BeanDefinition
    {
        $methodsToRemove = [];

        foreach ($definition->beanConditions as $method => $conditions) {
            $owner = $definition->class()."::{$method}()";

            $keep = $context->conditions->matchesList($conditions, $definition->source, $owner, beanPhase: null, registry: $context->definitions);

            foreach ($conditions as $condition) {
                $outcome = $context->conditions->evaluate($condition, $context->definitions);
                $context->report->record($owner, $condition::class, $outcome);
            }

            if (! $keep) {
                $methodsToRemove[] = $method;
            }
        }

        return $definition->withoutBeanMethods($methodsToRemove);
    }
}

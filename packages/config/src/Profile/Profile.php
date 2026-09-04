<?php

declare(strict_types=1);

namespace Firefly\Config\Profile;

use Attribute;

/**
 * Gates a component to one or more active profiles: the component exists only when at least one of
 * $names is in the active Profiles (OR, never AND — see Profiles::accepts(), which is the single
 * implementation of that predicate).
 *
 * WHAT THIS DOCBLOCK USED TO CLAIM, AND WHY THAT MATTERED. It said the predicate was "evaluated by
 * conditional registration (firefly/autoconfigure, M5)". It was not, anywhere: the attribute was
 * exported, documented and reachable, and `grep -rn 'Profile::class' packages/<any>/src` matched
 * ZERO lines of production code. Nothing scanned for it and nothing evaluated it, so every class
 * carrying #[Profile('prod')] was registered under every profile — the annotation read as a
 * guarantee and behaved as a comment. The lesson is recorded here rather than quietly deleted: a
 * docblock that promises an evaluator in another package is a promise nothing tests, and this one
 * went unkept through several milestones.
 *
 * WHAT HONOURS IT TODAY. Within firefly/config the chain is complete and covered by tests:
 * ProfileRequirement reads the attribute once at scan time, ConfigPropertiesScanner records the
 * result on ConfigPropertiesDescriptor, the compiled config-properties.php carries it, and
 * ConfigRegistrar refuses to bind a #[ConfigProperties] DTO whose profiles are not active.
 *
 * Gating a general #[Component] — anything that is not a #[ConfigProperties] DTO — additionally
 * needs firefly/context to record the requirement while it scans and to apply Profiles::accepts()
 * in its condition pipeline; firefly/config sits below Context in the layer graph and cannot reach
 * up to do it. Until that lands, prefer firefly/context's #[ConditionalOnProfile] for non-DTO
 * beans: it is the same predicate, already wired into ConditionEvaluator.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Profile
{
    /** @var list<string> */
    public array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values($names);
    }
}

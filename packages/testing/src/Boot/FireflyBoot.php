<?php

declare(strict_types=1);

namespace Firefly\Testing\Boot;

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Foundation\Application;

/**
 * Two boot-time footgun-killers the hand-rolled Family A test bases kept re-deriving by hand:
 *
 *  - ConditionEvaluator's constructor is arity-2 (Config + Profiles), but ~30 ad hoc test bases
 *    across the monorepo were drafted with a 1-arg `new ConditionEvaluator($config)` call that
 *    fails to compile — the second (Profiles) argument is easy to forget because most tests never
 *    exercise #[ConditionalOnProfile] and so never notice the missing collaborator until the
 *    constructor throws. makeConditionEvaluator() centralizes the correct arity-2 call in one
 *    place and defaults $profiles to an empty Profiles([]) so the common (no active profiles)
 *    case stays a 1-arg call at every call site.
 *  - ScheduledManifest is a cross-package boot dependency: the actuator/observability
 *    ScheduledTasksEndpoint singleton resolves it eagerly during container boot even in test
 *    slices that never touch scheduling, so every such slice must bind SOME ScheduledManifest
 *    instance before booting or resolution fails. stubScheduledManifest() binds the canonical
 *    empty `new ScheduledManifest([])` stub — no scheduled tasks — and returns it so callers can
 *    assert against the same instance if needed.
 */
final class FireflyBoot
{
    /**
     * The arity-2 ConditionEvaluator ctor, in one place (Config + Profiles, defaulting to no
     * active profiles) — see class docblock for why the second argument is the footgun.
     */
    public static function makeConditionEvaluator(Config $config, Profiles $profiles = new Profiles([])): ConditionEvaluator
    {
        return new ConditionEvaluator($config, $profiles);
    }

    /**
     * Bind + return the empty ScheduledManifest stub that satisfies the eager
     * ScheduledTasksEndpoint singleton — see class docblock for why this binding is needed even
     * in test slices with no scheduled tasks of their own.
     */
    public static function stubScheduledManifest(Application $app): ScheduledManifest
    {
        $manifest = new ScheduledManifest([]);
        $app->instance(ScheduledManifest::class, $manifest);

        return $manifest;
    }
}

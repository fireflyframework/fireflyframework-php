<?php

declare(strict_types=1);

use Firefly\Context\Tests\Support\LaraflyTestCase;
use Illuminate\Foundation\Application;

/**
 * M4 review #6, Important 2: the #[Lazy]/Octane disclosure previously bolded only the SMALLER
 * consequence ("its #[PreDestroy] does not run") and omitted the larger one: under Octane, a
 * #[Lazy] Scope::Singleton is not merely missing teardown — it is REBUILT ON EVERY REQUEST. This is
 * a DELIBERATE, DISCLOSED-NOT-FIXED gap (see DisposableBeanRegistry's own docblock for why no
 * correct narrow fix exists at the extend() seam this package is committed to). This test exists
 * purely to PIN the actual, measured behavior as executable documentation, so it cannot silently
 * drift without a red test, and so the true consequence is provable rather than merely asserted in
 * prose.
 *
 * Mirrors `Laravel\Octane\Worker::handle()`'s exact per-request shape — verified against the
 * installed laravel/octane source by OctaneListenerTest's own docblock — `CurrentApplication::set(
 * $sandbox = clone $this->app)`, serve, `$sandbox->flush()`. "Eager" here means resolved into the
 * long-lived WORKER application itself, once, before any request/sandbox exists (exactly what
 * EagerSingletonsPass, phase 900, does for a non-#[Lazy] Scope::Singleton at boot — see
 * EagerSingletonsPassTest). "Lazy" means never resolved on the worker app at all (exactly what
 * EagerSingletonsPass does for a #[Lazy] one: skip it entirely), so its first resolution always
 * happens on whichever sandbox asks for it first.
 */
/**
 * $identity is the construction SEQUENCE NUMBER, captured once at construction — deliberately NOT
 * spl_object_id(): once a per-request sandbox (and everything built into it) is unset(), PHP is free
 * to garbage-collect that object and RECYCLE its object-id for the very next object created, which
 * would make two genuinely DIFFERENT instances report the same spl_object_id() across iterations of
 * the loop below and silently defeat this control. A monotonically increasing counter captured onto
 * the instance itself has no such collision risk.
 */
final class EagerIdentityProbe
{
    public static int $constructions = 0;

    public readonly int $identity;

    public function __construct()
    {
        $this->identity = ++self::$constructions;
    }
}

final class LazyIdentityProbe
{
    public static int $constructions = 0;

    public readonly int $identity;

    public function __construct()
    {
        $this->identity = ++self::$constructions;
    }
}

uses(LaraflyTestCase::class);

/**
 * @return array{eagerIds: list<int>, lazyIds: list<int>}
 */
function simulateOctaneRequestsOverSingletons(Application $app, int $requests): array
{
    $eagerIds = [];
    $lazyIds = [];

    for ($i = 0; $i < $requests; $i++) {
        // Laravel\Octane\Worker::handle(): CurrentApplication::set($sandbox = clone $this->app) ...
        $sandbox = clone $app;

        $eagerIds[] = $sandbox->make(EagerIdentityProbe::class)->identity;
        $lazyIds[] = $sandbox->make(LazyIdentityProbe::class)->identity;

        // ... serve the request ... $sandbox->flush(); unset($sandbox);
        $sandbox->flush();
        unset($sandbox);
    }

    return ['eagerIds' => $eagerIds, 'lazyIds' => $lazyIds];
}

it('pins the ACTUAL #[Lazy]/Octane behavior: an eagerly-resolved singleton is the SAME instance across every request; a #[Lazy] one is rebuilt on EVERY request', function () {
    EagerIdentityProbe::$constructions = 0;
    LazyIdentityProbe::$constructions = 0;

    /** @var LaraflyTestCase $this */
    $app = $this->laraflyApp();

    $app->singleton(EagerIdentityProbe::class);
    $app->singleton(LazyIdentityProbe::class);

    // Simulate EagerSingletonsPass (phase 900): a non-#[Lazy] singleton is resolved into the WORKER
    // application itself, once, during boot, before any request is ever served.
    $app->make(EagerIdentityProbe::class);

    // LazyIdentityProbe is deliberately left untouched here — mirroring #[Lazy] skipping
    // EagerSingletonsPass entirely (see EagerSingletonsPassTest's "skipping #[Lazy] ones" case) —
    // so nothing resolves it until "inside a request" below.

    $result = simulateOctaneRequestsOverSingletons($app, 3);

    // Eager: constructed ONCE, and the SAME instance is observed across all 3 "requests" — a true
    // singleton, because it was already built into the long-lived $app before any sandbox was ever
    // cloned, and clone shares that cached instance's reference into every sandbox.
    expect(EagerIdentityProbe::$constructions)->toBe(1)
        ->and(array_unique($result['eagerIds']))->toHaveCount(1);

    // Lazy: constructed a NEW time on EVERY request — Scope::Singleton + #[Lazy] is NOT a singleton
    // at all under Octane. This is the larger, previously-undisclosed consequence M4 review #6 found.
    expect(LazyIdentityProbe::$constructions)->toBe(3)
        ->and(array_unique($result['lazyIds']))->toHaveCount(3);
});

// --- fault injection: prove the control above actually discriminates, rather than passing vacuously ---

it('[FAULT INJECTION] the control fails if the "eager" probe is ALSO never resolved before the request loop', function () {
    // Same setup as above, EXCEPT the one variable under test: the "eager" probe is left unresolved
    // on $app too — i.e. treated exactly like the #[Lazy] one. If the assertion above could pass no
    // matter what (e.g. because clone-of-a-bare-Container always preserves identity regardless of
    // prior resolution), THIS run would ALSO show "same instance across every request" and the test
    // would be worthless. It does not: without a prior resolution on $app, both probes are rebuilt
    // per request, proving the original test's "eager" result is genuinely conditioned on resolving
    // the singleton into the worker BEFORE cloning, not an artifact of the harness.
    EagerIdentityProbe::$constructions = 0;
    LazyIdentityProbe::$constructions = 0;

    /** @var LaraflyTestCase $this */
    $app = $this->laraflyApp();

    $app->singleton(EagerIdentityProbe::class);
    $app->singleton(LazyIdentityProbe::class);

    // Deliberately OMITTED: $app->make(EagerIdentityProbe::class) — the fault injection.

    $result = simulateOctaneRequestsOverSingletons($app, 3);

    expect(EagerIdentityProbe::$constructions)->toBe(3)
        ->and(array_unique($result['eagerIds']))->toHaveCount(3);
});

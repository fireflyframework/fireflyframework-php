<?php

declare(strict_types=1);

use Illuminate\Container\Container;

/**
 * Characterization tests pinning UNPUBLISHED Illuminate\Container behavior that firefly/context's boot engine
 * depends on. If a Laravel upgrade breaks one of these, THIS test tells us — not production.
 * See docs/superpowers/specs/2026-07-15-m4-context-design-decisions.md.
 */
final class ContractProbe
{
    public string $marks = '';
}
final class ContractProbeWrapper
{
    public function __construct(public object $inner) {}
}

it('extend() REPLACES the resolved object (resolving callbacks cannot)', function () {
    $c = new Container;
    $c->singleton(ContractProbe::class, fn () => new ContractProbe);
    $c->extend(ContractProbe::class, fn (object $o): object => new ContractProbeWrapper($o));

    // The extender's RETURN VALUE is what the container yields — this is Spring's initializeBean point.
    expect($c->make(ContractProbe::class))->toBeInstanceOf(ContractProbeWrapper::class);
});

it('resolving() callbacks CANNOT replace the object (return values are discarded)', function () {
    $c = new Container;
    $c->singleton(ContractProbe::class, fn () => new ContractProbe);
    $c->resolving(ContractProbe::class, fn (object $o): object => new ContractProbeWrapper($o));

    // fireCallbackArray() throws the return value away — mutation only.
    expect($c->make(ContractProbe::class))->toBeInstanceOf(ContractProbe::class);
});

it('extend() result is what gets CACHED as the singleton (extender runs pre-cache)', function () {
    $c = new Container;
    $c->singleton(ContractProbe::class, fn () => new ContractProbe);
    $c->extend(ContractProbe::class, fn (object $o): object => new ContractProbeWrapper($o));

    expect($c->make(ContractProbe::class))->toBe($c->make(ContractProbe::class));
});

it('runs the extender EXACTLY ONCE per singleton', function () {
    $c = new Container;
    $count = 0;
    $c->singleton(ContractProbe::class, fn () => new ContractProbe);
    $c->extend(ContractProbe::class, function (object $o) use (&$count): object {
        $count++;

        return $o;
    });

    $c->make(ContractProbe::class);
    $c->make(ContractProbe::class);

    expect($count)->toBe(1);
});

it('runs the extender PER INSTANCE for a transient binding', function () {
    $c = new Container;
    $count = 0;
    $c->bind(ContractProbe::class, fn () => new ContractProbe);
    $c->extend(ContractProbe::class, function (object $o) use (&$count): object {
        $count++;

        return $o;
    });

    $c->make(ContractProbe::class);
    $c->make(ContractProbe::class);

    expect($count)->toBe(2);
});

it('re-runs the extender for a scoped binding after forgetScopedInstances()', function () {
    $c = new Container;
    $count = 0;
    $c->scoped(ContractProbe::class, fn () => new ContractProbe);
    $c->extend(ContractProbe::class, function (object $o) use (&$count): object {
        $count++;

        return $o;
    });

    $c->make(ContractProbe::class);
    $c->forgetScopedInstances();
    $c->make(ContractProbe::class);

    expect($count)->toBe(2);
});

it('flush() does NOT clear extenders — CHARACTERIZED, DELIBERATELY UNHANDLED, see below', function () {
    $c = new Container;
    $applied = 0;
    $c->singleton(ContractProbe::class, fn () => new ContractProbe);
    $c->extend(ContractProbe::class, function (object $o) use (&$applied): object {
        $applied++;

        return $o;
    });
    $c->make(ContractProbe::class);

    $c->flush();

    // Re-bind after flush and resolve: the OLD extender is still registered.
    $c->singleton(ContractProbe::class, fn () => new ContractProbe);
    $c->make(ContractProbe::class);

    // 2 => extenders survived flush(). If something ever flush()es the SAME long-lived container
    // and then reboots the FireflyKernel over it, RegisterBeanPostProcessorsPass would install a
    // SECOND composite extender per abstract on top of the surviving one from before the flush —
    // two distinct BeanPostProcessorChain instances (and two distinct WeakMaps), so the chain's own
    // idempotency guard (see BeanPostProcessorChain's docblock) does NOT stop #[PostConstruct] from
    // running twice per bean. `firefly/container`'s Container::getAll() tag duplication is the other
    // half of the same hazard.
    //
    // Nothing in packages/context/src/ accounts for this today — it is CHARACTERIZED here, not
    // handled, and that is a deliberate, currently-safe choice, not an oversight: no shipped caller
    // ever flush()es a booted container and reboots the SAME FireflyKernel over it (Octane resets
    // per-request STATE via StateResetter, and only ever discards a request's disposable sandbox
    // clone — see the Octane section below — it never flush()es the long-lived worker application
    // itself). Should a future milestone introduce such a caller, this is the seam that needs a
    // guard (e.g. a per-container "already installed" sentinel that RegisterBeanPostProcessorsPass
    // checks, mirroring `firefly/container`'s own `firefly.container.registered` idiom) BEFORE that
    // caller ships — do not assume this test merely documents a solved problem.
    expect($applied)->toBe(2);
});

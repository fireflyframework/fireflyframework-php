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

it('flush() does NOT clear extenders (a reboot would double-apply the chain)', function () {
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

    expect($applied)->toBe(2); // 2 => extenders survived flush(). The engine MUST account for this.
});

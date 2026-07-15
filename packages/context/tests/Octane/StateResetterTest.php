<?php

declare(strict_types=1);

use Firefly\Container\Scope;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Context\Octane\StateResetter;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;

/**
 * StateResetter is deliberately framework-agnostic — no Octane types appear anywhere in this
 * file, or in the class itself — so its reset mechanics are unit-testable without laravel/octane
 * installed at all. OctaneListenerTest (same directory) is where the REAL Octane event shapes,
 * and the sandbox-vs-app distinction (INVARIANT 7), get exercised.
 *
 * Exercised through a REAL Illuminate\Container\Container and a REAL DisposableBeanRegistry — no
 * mocks of our own interfaces.
 */
final class ScopedResettableBean
{
    public bool $destroyed = false;

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->destroyed = true;
    }
}

function scopedResettableBeanRegistry(Container $container): DisposableBeanRegistry
{
    $manifest = new ContextManifest([
        new ContextDescriptor(class: ScopedResettableBean::class, preDestroy: ['shutdown']),
    ]);

    return new DisposableBeanRegistry(new InitDestroyInvoker($container, $manifest));
}

it('INVARIANT 8: drains scoped #[PreDestroy] callbacks BEFORE forgetScopedInstances() runs', function () {
    $container = new Container;
    $registry = scopedResettableBeanRegistry($container);
    $container->instance(DisposableBeanRegistry::class, $registry);

    $container->scoped(ScopedResettableBean::class, static fn (): ScopedResettableBean => new ScopedResettableBean);
    $first = $container->make(ScopedResettableBean::class);
    $registry->register($first, ScopedResettableBean::class, Scope::Scoped);

    (new StateResetter)->reset($container);

    // #[PreDestroy] ran on the ORIGINAL instance before it was dropped...
    expect($first->destroyed)->toBeTrue();

    // ...and forgetScopedInstances() actually ran: the next make() rebuilds a fresh instance.
    $second = $container->make(ScopedResettableBean::class);
    expect($second)->not->toBe($first)
        ->and($second->destroyed)->toBeFalse();
});

it('drains the scoped ledger: a second reset() does not re-invoke #[PreDestroy]', function () {
    $container = new Container;
    $registry = scopedResettableBeanRegistry($container);
    $container->instance(DisposableBeanRegistry::class, $registry);

    $container->scoped(ScopedResettableBean::class, static fn (): ScopedResettableBean => new ScopedResettableBean);
    $bean = $container->make(ScopedResettableBean::class);
    $registry->register($bean, ScopedResettableBean::class, Scope::Scoped);

    $resetter = new StateResetter;
    $resetter->reset($container);
    $bean->destroyed = false; // reset the marker by hand to prove the SECOND call touches nothing
    $resetter->reset($container);

    expect($bean->destroyed)->toBeFalse();
});

it('never touches Singleton-scope #[PreDestroy] beans — only the SCOPED ledger is drained per reset', function () {
    $container = new Container;
    $registry = scopedResettableBeanRegistry($container);
    $container->instance(DisposableBeanRegistry::class, $registry);

    $singleton = new ScopedResettableBean;
    $registry->register($singleton, ScopedResettableBean::class, Scope::Singleton);

    (new StateResetter)->reset($container);

    expect($singleton->destroyed)->toBeFalse();
});

it('does not fail when no DisposableBeanRegistry is bound at all — forgetScopedInstances() still runs', function () {
    $container = new Container;
    $container->scoped(ScopedResettableBean::class, static fn (): ScopedResettableBean => new ScopedResettableBean);
    $first = $container->make(ScopedResettableBean::class);

    (new StateResetter)->reset($container);

    $second = $container->make(ScopedResettableBean::class);
    expect($second)->not->toBe($first);
});

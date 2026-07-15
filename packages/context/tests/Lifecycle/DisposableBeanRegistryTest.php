<?php

declare(strict_types=1);

use Firefly\Container\Scope;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;

/**
 * DisposableBeanRegistry is exercised through REAL fixture beans and a REAL InitDestroyInvoker
 * over a REAL Illuminate container — no mocks of our own interfaces.
 */
final class DisposalLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class DisposableBean
{
    public function __construct(private readonly DisposalLog $log, private readonly string $id) {}

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->log->record($this->id);
    }
}

final class NoPreDestroyBean {}

function makeDisposableRegistry(): DisposableBeanRegistry
{
    // Hand-built ContextManifest standing in for a real ContextScanner scan: DisposableBean is
    // declared inline in this test file (not under a scannable PSR-4 directory).
    $manifest = new ContextManifest([
        new ContextDescriptor(class: DisposableBean::class, preDestroy: ['shutdown']),
    ]);

    return new DisposableBeanRegistry(new InitDestroyInvoker(new Container, $manifest));
}

it('drainSingletons() invokes #[PreDestroy] on a registered Singleton-scope bean', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    // Held in a local var deliberately: DisposableBeanRegistry holds only a \WeakReference, so
    // without a surviving strong reference here PHP's refcounting would free the bean the
    // instant register() returns — before drain() ever ran. The registry relies on the REAL
    // owner (the container, in production) to keep the bean alive; these tests play that role.
    $bean = new DisposableBean($log, 'svc');
    $registry->register($bean, DisposableBean::class, Scope::Singleton);
    $registry->drainSingletons();

    expect($log->entries)->toBe(['svc']);
});

it('tracks Singleton and Scoped beans SEPARATELY: drainScoped() does not touch Singleton-scope beans, and vice versa', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    $singleton = new DisposableBean($log, 'singleton');
    $scopedBean = new DisposableBean($log, 'scoped');
    $registry->register($singleton, DisposableBean::class, Scope::Singleton);
    $registry->register($scopedBean, DisposableBean::class, Scope::Scoped);

    $registry->drainScoped();
    expect($log->entries)->toBe(['scoped']);

    $registry->drainSingletons();
    expect($log->entries)->toBe(['scoped', 'singleton']);
});

it('destroys in REVERSE registration order — dependents (registered later) before dependencies', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    $first = new DisposableBean($log, 'first');
    $second = new DisposableBean($log, 'second');
    $third = new DisposableBean($log, 'third');
    $registry->register($first, DisposableBean::class, Scope::Singleton);
    $registry->register($second, DisposableBean::class, Scope::Singleton);
    $registry->register($third, DisposableBean::class, Scope::Singleton);

    $registry->drainSingletons();

    expect($log->entries)->toBe(['third', 'second', 'first']);
});

it('drain empties the ledger: a second drain call re-invokes nothing', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    $bean = new DisposableBean($log, 'once');
    $registry->register($bean, DisposableBean::class, Scope::Singleton);

    $registry->drainSingletons();
    $registry->drainSingletons();

    expect($log->entries)->toBe(['once']);
});

it('never tracks Scope::Transient beans (prototype-scoped beans are not lifecycle-managed)', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    $bean = new DisposableBean($log, 'transient');
    $registry->register($bean, DisposableBean::class, Scope::Transient);

    $registry->drainSingletons();
    $registry->drainScoped();

    expect($log->entries)->toBe([]);
});

it('never tracks a bean whose declared class has no #[PreDestroy] method, without throwing', function () {
    $registry = makeDisposableRegistry();

    $registry->register(new NoPreDestroyBean, NoPreDestroyBean::class, Scope::Singleton);
    $registry->drainSingletons();

    expect(true)->toBeTrue();
});

// --- invariant: \WeakReference, never a strong ref — a GC'd bean is silently skipped ---

it('holds a \WeakReference: a garbage-collected bean is silently skipped on drain, with no crash and no invocation', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    $bean = new DisposableBean($log, 'collected');
    $registry->register($bean, DisposableBean::class, Scope::Singleton);
    unset($bean);
    gc_collect_cycles();

    $registry->drainSingletons();

    expect($log->entries)->toBe([]);
});

it('a GC\'d bean does not break destruction of its still-alive, later-registered siblings', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    $collected = new DisposableBean($log, 'collected');
    $registry->register($collected, DisposableBean::class, Scope::Singleton);
    unset($collected);
    gc_collect_cycles();

    $alive = new DisposableBean($log, 'alive');
    $registry->register($alive, DisposableBean::class, Scope::Singleton);

    $registry->drainSingletons();

    expect($log->entries)->toBe(['alive']);
});

// --- M4 review #5 (Important, "related" finding): register() compacts dead singleton entries ---
// --- so the ledger does not grow one dead entry per request forever under Octane. ---

it('register() PRUNES already-dead singleton entries from the ledger — bounds memory to distinct live/tracked entries, not total registrations', function () {
    $log = new DisposalLog;
    $registry = makeDisposableRegistry();

    // Simulate many Octane requests each first-resolving (and immediately discarding) a #[Lazy]
    // singleton: register, then let it die, before the NEXT registration happens.
    for ($i = 0; $i < 50; $i++) {
        $bean = new DisposableBean($log, "churned-{$i}");
        $registry->register($bean, DisposableBean::class, Scope::Singleton);
        unset($bean);
        gc_collect_cycles();
    }

    $alive = new DisposableBean($log, 'alive');
    $registry->register($alive, DisposableBean::class, Scope::Singleton);

    // A ReflectionProperty peek (test-only — the standing "no Reflection in packages/context/src/"
    // invariant governs production code, not tests) is the only way to observe the internal ledger
    // size directly: proves compaction actually shrinks the array as churn happens, not merely that
    // drain() still behaves correctly either way (drain() would look identical with or without
    // compaction — see the two tests above — so THIS is the test that actually discriminates the
    // improvement from a no-op).
    $singletons = new ReflectionProperty(DisposableBeanRegistry::class, 'singletons');
    $ledger = $singletons->getValue($registry);

    expect($ledger)->toHaveCount(1);

    $registry->drainSingletons();
    expect($log->entries)->toBe(['alive']);
});

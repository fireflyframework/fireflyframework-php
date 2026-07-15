<?php

declare(strict_types=1);

use Firefly\Container\Scope;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\PreDestroy;
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
    return new DisposableBeanRegistry(new InitDestroyInvoker(new Container));
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

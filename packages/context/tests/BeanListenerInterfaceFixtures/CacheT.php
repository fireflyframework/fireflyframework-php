<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * M4 review #7, Minor 1 — the dedupe guard's ONLY genuinely discriminating shape. Produced by
 * `ConfigT::makeT()`, an interface-returning `#[Bean(scope: Scope::Transient)]` factory: every
 * `make(TransientListenerPort::class)` call rebuilds a NEW `CacheT` instance and re-invokes the
 * composite extender in `RegisterBeanPostProcessorsPass`, unlike `Scope::Singleton` (ARM B /
 * `ListenerPort`), whose second `make()` call returns the cached instance and never re-invokes
 * anything. That is precisely why `BeanProducedInterfaceListenerTest`'s old "never registers ...
 * twice" test — built on the Singleton `ListenerPort` — could not fail even with the
 * `$registered[$concreteClass]` guard deleted: the extender it meant to re-trigger was never
 * re-triggered at all. Resolving `TransientListenerPort::class` several times and dispatching ONE
 * event is what actually exercises the guard: with it, `'T:listener'` fires once per event no matter
 * how many times the bean was resolved beforehand; without it, it fires once MORE per resolution.
 */
final class CacheT implements TransientListenerPort
{
    public function __construct(private readonly ListenerFireRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onProbe(ProbeEvent $event): void
    {
        $this->recorder->record('T:listener');
    }
}

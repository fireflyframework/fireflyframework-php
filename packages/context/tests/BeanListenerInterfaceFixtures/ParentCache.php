<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * ARM C — M4 review #7, Important: the regression case. Produced by `ConfigC::makeC()`, whose
 * `#[Bean]` method's declared return type is this CONCRETE class, but the factory actually returns a
 * `ChildCache` instance (a concrete SUBCLASS) — the third shape of `$bean->returns` relative to the
 * runtime concrete class, alongside identical (CacheA/ConfigA) and interface (CacheB/ConfigB/
 * ListenerPort). Unlike an interface, `ParentCache` IS scanned by `ContextScanner` (it is concrete),
 * so `RegisterEventListenersPass`'s boot-time sweep already finds and registers this
 * `#[AsEventListener]` via `forClass($bean->returns)` = `forClass(ParentCache::class)`. `ChildCache`
 * (below) declares no listener method of its own — it inherits this one via
 * `ReflectionClass::getMethods()`, which is why `ContextScanner`'s descriptor for `ChildCache` ALSO
 * reports this listener, and why `RegisterBeanPostProcessorsPass`'s late-bound recovery path must NOT
 * register it again for the concrete class `ChildCache` — doing so is exactly what fired
 * 'C:listener' twice per event before the fix (`$concreteClass === $declaredClass` is false for this
 * shape too, just like the interface arm, but here the sweep already handled it).
 */
class ParentCache
{
    public function __construct(private readonly ListenerFireRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onProbe(ProbeEvent $event): void
    {
        $this->recorder->record('C:listener');
    }
}

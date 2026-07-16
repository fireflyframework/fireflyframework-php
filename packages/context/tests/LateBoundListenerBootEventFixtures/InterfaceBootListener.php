<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerBootEventFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * ARM INTERFACE — produced by `BootConfig::makeInterface()`, whose `#[Bean]` method's declared return
 * type is `BootListenerPort`, an INTERFACE. Structurally identical to ConcreteBootListener (same
 * #[AsEventListener] method) — the only variable is the factory's declared return type, plus this
 * class's own `implements BootListenerPort`. Its listener is registered only once
 * `RegisterBeanPostProcessorsPass`'s composite extender for `BootListenerPort` actually runs — i.e.
 * once something resolves the bean.
 */
final class InterfaceBootListener implements BootListenerPort
{
    public function __construct(private readonly BootRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onBoot(BootProbeEvent $event): void
    {
        $this->recorder->record('Interface:listener');
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerBootEventFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * ARM CONCRETE — produced by `BootConfig::makeConcrete()`, whose `#[Bean]` method's declared return
 * type is this CONCRETE class. `ContextScanner` scans it directly, so `RegisterEventListenersPass`'s
 * boot-time sweep (phase 800) already registers this listener BEFORE `EagerSingletonsPass` (phase
 * 900) ever runs — regardless of whether or when anything resolves the bean.
 */
final class ConcreteBootListener
{
    public function __construct(private readonly BootRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onBoot(BootProbeEvent $event): void
    {
        $this->recorder->record('Concrete:listener');
    }
}

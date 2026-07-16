<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerBootEventFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

/**
 * Two `#[Bean]` factories, both default `Scope::Singleton` (eager, not `#[Lazy]`) — so
 * `EagerSingletonsPass` (phase 900) resolves both, in (order, abstract) order:
 * `BootListenerPort` < `ConcreteBootListener` alphabetically, but `EventPublisher`'s own `#[Order(-100)]`
 * (see EventPublisher.php) sorts it before EITHER of these regardless of their own tie-break, so the
 * publish always happens before both are resolved.
 */
#[Configuration]
final class BootConfig
{
    #[Bean]
    public function makeConcrete(BootRecorder $recorder): ConcreteBootListener
    {
        return new ConcreteBootListener($recorder);
    }

    #[Bean]
    public function makeInterface(BootRecorder $recorder): BootListenerPort
    {
        return new InterfaceBootListener($recorder);
    }
}

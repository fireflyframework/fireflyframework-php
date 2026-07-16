<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Produced by `TransientMatrixConfig::makeConcrete()`, a `Scope::Transient` `#[Bean]` factory whose
 * declared return type is this CONCRETE class — scanned and registered by the boot-time sweep
 * regardless of scope, exactly like LazyConcreteListener/ScopedConcreteListener.
 */
final class TransientConcreteListener
{
    public function __construct(private readonly MatrixRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(TransientEvent $event): void
    {
        $this->recorder->record('TransientConcrete:listener');
    }
}

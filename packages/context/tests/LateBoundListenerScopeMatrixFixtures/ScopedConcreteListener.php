<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Produced by `ScopedConfig::makeConcrete()`, a `Scope::Scoped` `#[Bean]` factory whose declared
 * return type is this CONCRETE class — scanned and registered by the boot-time sweep regardless of
 * scope, exactly like LazyConcreteListener.
 */
final class ScopedConcreteListener
{
    public function __construct(private readonly MatrixRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(ScopedEvent $event): void
    {
        $this->recorder->record('ScopedConcrete:listener');
    }
}

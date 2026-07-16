<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Structurally identical to LazyConcreteListener — the only variable is the declared return type of
 * the `#[Lazy]` `#[Bean]` factory that produces it (`LazyPort`, an interface) and `implements
 * LazyPort` itself.
 */
final class LazyInterfaceListener implements LazyPort
{
    public function __construct(private readonly MatrixRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(LazyEvent $event): void
    {
        $this->recorder->record('LazyInterface:listener');
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Produced by `LazyConfig::makeConcrete()`, a `#[Lazy]` `#[Bean]` factory whose declared return type
 * is this CONCRETE class. `ContextScanner` scans it regardless of laziness — `#[Lazy]` only ever
 * gates EagerSingletonsPass's eager resolution, never the boot-time listener sweep, which reads
 * purely from the manifest without resolving anything — so this listener registers at boot
 * REGARDLESS of whether anything ever resolves the bean.
 */
final class LazyConcreteListener
{
    public function __construct(private readonly MatrixRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(LazyEvent $event): void
    {
        $this->recorder->record('LazyConcrete:listener');
    }
}

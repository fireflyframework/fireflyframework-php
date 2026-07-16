<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Context\Event\AsEventListener;

final class ScopedInterfaceListener implements ScopedPort
{
    public function __construct(private readonly MatrixRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(ScopedEvent $event): void
    {
        $this->recorder->record('ScopedInterface:listener');
    }
}

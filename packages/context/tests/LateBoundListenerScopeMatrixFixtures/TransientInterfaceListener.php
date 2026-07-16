<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Context\Event\AsEventListener;

final class TransientInterfaceListener implements TransientMatrixPort
{
    public function __construct(private readonly MatrixRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(TransientEvent $event): void
    {
        $this->recorder->record('TransientInterface:listener');
    }
}

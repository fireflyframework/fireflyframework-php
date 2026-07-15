<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Event\AsEventListener;

/**
 * Registered with the LOWER #[AsEventListener] order (dispatched first) and deliberately returns
 * `false` — an easy, realistic accident (e.g. `return $repository->delete($id);`). Without
 * DispatcherEventPublisher::guardListener() wired by RegisterEventListenersPass, Illuminate's
 * dispatch loop would break here and SecondGuardListener below would never run.
 */
#[Component]
final class FalsyFirstListener
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    #[AsEventListener(order: 1)]
    public function onGuard(GuardEvent $event): bool
    {
        $this->recorder->record('guard:first');

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Event\AsEventListener;

/**
 * Registered with the HIGHER #[AsEventListener] order (dispatched second, after
 * FalsyFirstListener). Its firing at all is the proof that a falsy first-listener return did not
 * starve the dispatch chain.
 */
#[Component]
final class SecondGuardListener
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    #[AsEventListener(order: 2)]
    public function onGuard(GuardEvent $event): void
    {
        $this->recorder->record('guard:second');
    }
}

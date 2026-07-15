<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Event\AsEventListener;

/**
 * Listens for DuringEagerEvent, published from DuringEagerPublisher's #[PostConstruct] while
 * EagerSingletonsPass is still resolving. If EventListeners (800) did not already run before
 * EagerSingletons (900), $received would stay false, silently.
 */
#[Component]
final class DuringEagerReceiver
{
    public bool $received = false;

    #[AsEventListener]
    public function onDuringEager(DuringEagerEvent $event): void
    {
        $this->received = true;
    }
}

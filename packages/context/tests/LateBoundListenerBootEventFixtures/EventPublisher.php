<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerBootEventFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Lifecycle\PostConstruct;

/**
 * `#[Order(-100)]` guarantees `EagerSingletonsPass` (phase 900) resolves THIS bean before either of
 * `BootConfig`'s two listener beans (both default order 0) — the exact `EagerSingletonsPassTest`
 * "an event published from a #[PostConstruct] during eager resolution IS received" scenario, reused
 * here to ask whether an interface-produced `#[Bean]` listener honours the SAME 800-before-900
 * guarantee that scenario proves for a plain #[Component] listener.
 */
#[Component]
#[Order(-100)]
final class EventPublisher
{
    public function __construct(private readonly DispatcherEventPublisher $publisher) {}

    #[PostConstruct]
    public function announce(): void
    {
        $this->publisher->publish(new BootProbeEvent);
    }
}

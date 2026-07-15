<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Lifecycle\PreDestroy;

/**
 * ARM A — the control: produced by `ConfigA::makeA()`, whose `#[Bean]` method's declared return type
 * is this CONCRETE class. Structurally IDENTICAL to CacheB (same method names/bodies) — the ONLY
 * variable between the two arms is the declared return type of the `#[Bean]` factory method that
 * produces them (see ConfigA/ConfigB).
 */
final class CacheA
{
    public function __construct(private readonly ListenerFireRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onProbe(ProbeEvent $event): void
    {
        $this->recorder->record('A:listener');
    }

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->recorder->record('A:predestroy');
    }
}

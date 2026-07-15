<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Lifecycle\PreDestroy;

/**
 * ARM B — the fault case: produced by `ConfigB::makeB()`, whose `#[Bean]` method's declared return
 * type is `ListenerPort`, an INTERFACE. Structurally IDENTICAL to CacheA (same method names/bodies)
 * — only `implements ListenerPort` and the factory's declared return type differ. Before the M4
 * review #6 Important-1 fix, `RegisterEventListenersPass` looked the listener up via
 * `contextManifest->forClass($bean->returns)` — i.e. `forClass(ListenerPort::class)`, which
 * `ContextScanner` never populates (interfaces are never scanned) — so 'B:listener' was NEVER
 * recorded, even though `#[PreDestroy]` fired correctly via the concrete class captured at init.
 */
final class CacheB implements ListenerPort
{
    public function __construct(private readonly ListenerFireRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onProbe(ProbeEvent $event): void
    {
        $this->recorder->record('B:listener');
    }

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->recorder->record('B:predestroy');
    }
}

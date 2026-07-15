<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;

/**
 * A shared, container-managed singleton every other fixture in this directory injects to record a
 * single, ordered log of everything that happened during boot — the IntegrationTest's one and only
 * observation point into the REAL, compiled-manifest-driven pipeline. Deliberately a real
 * #[Component] (not a hand-wired test double bound via ->instance()): every fixture that depends on
 * it gets the SAME instance purely through the REAL ContainerRegistrar/EagerSingletonsPass wiring,
 * exactly as a real application's shared services would.
 */
#[Component]
final class WidgetRecorder
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

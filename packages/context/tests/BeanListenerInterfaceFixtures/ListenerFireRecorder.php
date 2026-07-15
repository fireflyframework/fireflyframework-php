<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Container\Attributes\Component;

/**
 * A shared, container-managed singleton both CacheA and CacheB inject to record what actually fired,
 * mirroring the IntegrationTest's WidgetRecorder idiom — a REAL #[Component], not a hand-wired test
 * double, so every fixture depending on it shares the exact same instance through real container
 * wiring.
 */
#[Component]
final class ListenerFireRecorder
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

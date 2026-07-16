<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerBootEventFixtures;

use Firefly\Container\Attributes\Component;

/**
 * A shared, container-managed singleton every fixture in this directory injects to record what
 * actually fired — the same REAL-collaborator idiom as BeanListenerInterfaceFixtures'
 * ListenerFireRecorder.
 */
#[Component]
final class BootRecorder
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

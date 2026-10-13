<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Tests\CapstoneFixtures;

use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

/**
 * The app-side #[EventListener] the provider=database capstone drives end to end. Its own directory so the inline
 * scanner run sees this listener and nothing else (the eda-postgres capstone precedent).
 */
final class DatabaseCapstoneListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('order.*')]
    public function onOrder(EventEnvelope $envelope): void
    {
        $this->spy->record($envelope->eventType);
    }
}

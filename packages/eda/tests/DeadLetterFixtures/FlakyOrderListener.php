<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\DeadLetterFixtures;

use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;
use RuntimeException;

/** Fails while $failing is true (the outage), records every envelope it handled once it is false (the fix). */
final class FlakyOrderListener
{
    public static bool $failing = true;

    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('order.*')]
    public function onOrder(EventEnvelope $envelope): void
    {
        if (self::$failing) {
            throw new RuntimeException('ledger unavailable');
        }

        $this->spy->record($envelope->eventType.'#'.($envelope->headers['x-replayed-from'] ?? 'first'));
    }
}

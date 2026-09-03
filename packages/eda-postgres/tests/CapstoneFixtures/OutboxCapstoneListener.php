<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\CapstoneFixtures;

use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

/**
 * The app-side #[EventListener] the outbox capstone drives END TO END. It lives in its OWN directory (not
 * tests/Fixtures) so the capstone's inline EventListenerScanner run sees exactly this one listener and nothing
 * else — the scanner walks a whole PSR-4 root, and tests/Fixtures also holds the relay's SpyDownstreamPublisher
 * and a DomainEvent sample that must stay out of the subscription set.
 *
 * It records into the shared ListenerSpy singleton, which is what lets the capstone assert the DIFFERENCE between
 * "the outbox row was marked PUBLISHED" and "a handler actually ran" — the exact gap the acked-without-delivery
 * defect hid.
 */
final class OutboxCapstoneListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('user.*')]
    public function onUserEvent(EventEnvelope $envelope): void
    {
        $this->spy->record($envelope->eventType);
    }
}

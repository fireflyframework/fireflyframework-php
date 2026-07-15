<?php

declare(strict_types=1);

namespace Firefly\Context\Event;

use DateTimeImmutable;

/**
 * Published when the context is shutting down — Spring's ContextClosedEvent, ported.
 *
 * FLAT, no base class — see ApplicationReadyEvent's docblock for why a shared "ApplicationEvent"
 * base is the bug, not the tidy option.
 */
final readonly class ContextClosedEvent
{
    public function __construct(public DateTimeImmutable $closedAt) {}
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Event;

use DateTimeImmutable;

/**
 * Published once the boot engine's context is fully wired — Spring's ContextRefreshedEvent, ported.
 *
 * FLAT, no base class — see ApplicationReadyEvent's docblock for why a shared "ApplicationEvent"
 * base is the bug, not the tidy option.
 */
final readonly class ContextRefreshedEvent
{
    public function __construct(public DateTimeImmutable $refreshedAt) {}
}

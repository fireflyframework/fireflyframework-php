<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use DateTimeImmutable;
use Firefly\Eda\EventEnvelope;

/**
 * One dead-lettered envelope plus the exception class + message that caused it. Immutable record; the envelope
 * already carries the x-original-topic / x-exception headers added by RetryingEventHandler. A replayable store fills
 * `id` (the handle replay and removal use) and `deadLetteredAt`; both stay null for an entry built by hand.
 */
final readonly class DeadLetterEntry
{
    public function __construct(
        public EventEnvelope $envelope,
        public string $exceptionClass,
        public string $exceptionMessage,
        public ?string $id = null,
        public ?DateTimeImmutable $deadLetteredAt = null,
    ) {}
}

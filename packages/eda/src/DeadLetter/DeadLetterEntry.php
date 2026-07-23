<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use Firefly\Eda\EventEnvelope;

/**
 * One dead-lettered envelope plus the exception class + message that caused it. Immutable record; the envelope
 * already carries the x-original-topic / x-exception headers added by RetryingEventHandler.
 */
final readonly class DeadLetterEntry
{
    public function __construct(
        public EventEnvelope $envelope,
        public string $exceptionClass,
        public string $exceptionMessage,
    ) {}
}

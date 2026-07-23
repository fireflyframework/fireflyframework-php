<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

use Firefly\Messaging\Message;

/**
 * One dead-lettered message plus the exception class + message that caused it. The message is already re-keyed to
 * the dead-letter topic and carries the x-original-topic / x-exception headers added by RetryingMessageHandler.
 */
final readonly class DeadLetterEntry
{
    public function __construct(
        public Message $message,
        public string $exceptionClass,
        public string $exceptionMessage,
    ) {}
}

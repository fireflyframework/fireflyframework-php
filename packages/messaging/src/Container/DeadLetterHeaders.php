<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Firefly\Messaging\Message;

/**
 * The diagnostic headers a container broker stamps on a dead-lettered message. `x-original-topic` and `x-exception`
 * are the names the in-memory path and PyFly's messaging already write; `x-exception` carries the SHORT class name
 * (PyFly's `type(exc).__name__`), `x-exception-message` the message (truncated), `x-delivery-attempts` how many
 * attempts were spent.
 */
final class DeadLetterHeaders
{
    public const string ORIGINAL_TOPIC = 'x-original-topic';

    public const string EXCEPTION = 'x-exception';

    public const string EXCEPTION_MESSAGE = 'x-exception-message';

    public const string ATTEMPTS = 'x-delivery-attempts';

    public const string REASON_RETRIES_EXHAUSTED = 'RetriesExhausted';

    /**
     * @return array<string, string>
     */
    public static function for(Message $message, DeliveryOutcome $outcome): array
    {
        return [
            self::ORIGINAL_TOPIC => $message->topic,
            self::EXCEPTION => self::reason($outcome),
            self::EXCEPTION_MESSAGE => mb_substr($outcome->failure?->getMessage() ?? '', 0, 1000),
            self::ATTEMPTS => (string) $outcome->attempts,
        ];
    }

    public static function reason(DeliveryOutcome $outcome): string
    {
        if ($outcome->failure === null) {
            return self::REASON_RETRIES_EXHAUSTED;
        }

        $class = $outcome->failure::class;
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}

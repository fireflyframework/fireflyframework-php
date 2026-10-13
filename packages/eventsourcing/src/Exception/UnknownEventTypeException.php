<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

/**
 * The registry cannot map an event: a class without #[EventType] is refused at APPEND (nothing is written that could
 * not be read back), and a stored type or version the registry does not know is refused at load.
 */
final class UnknownEventTypeException extends EventSourcingException
{
    public static function forClass(string $class): self
    {
        return new self("The event class [{$class}] is not registered: give it #[EventType(name: …, version: …)] and run php artisan firefly:cache, or register it on the EventTypeRegistry.", 'EVENT_TYPE_UNKNOWN');
    }

    public static function forType(string $type): self
    {
        return new self("No event class is registered for the stored event type [{$type}].", 'EVENT_TYPE_UNKNOWN');
    }

    public static function version(string $type, int $stored, int $registered): self
    {
        return new self("The stored event [{$type}] is version {$stored} after upcasting, but its class is version {$registered}: register an EventUpcaster from version {$stored}.", 'EVENT_TYPE_VERSION');
    }
}

<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Exception\EventSerializationException;
use Firefly\EventSourcing\Exception\UnknownEventTypeException;
use Throwable;

/**
 * DomainEvent <-> stored event, without reflection.
 *
 * OUT: the registered type and version, and the payload. That is the event's own toPayload() for a
 * SerializableEvent. For any other event it is its PUBLIC properties minus the base's identity (eventId,
 * occurredAt travel in the envelope), normalized by PayloadNormalizer.
 *
 * IN: the stored version (after upcasting) must be the registered one, or an upcaster is missing. A
 * SerializableEvent builds itself. Any other event is constructed with named arguments from the payload, each
 * coerced by the parameter type the scanner compiled:
 *
 *  - JSON returns 12.0 as 12, so a float parameter takes an int;
 *  - a backed enum takes its value, a date its ISO string;
 *  - `eventId` and `occurredAt` come from the envelope, so a reloaded event keeps its identity when its
 *    constructor forwards them (the documented pattern).
 */
final class EventMapper
{
    public function __construct(private readonly EventTypeRegistry $types) {}

    public function types(): EventTypeRegistry
    {
        return $this->types;
    }

    public function toNewEvent(DomainEvent $event, EventMetadata $metadata = new EventMetadata): NewEvent
    {
        $descriptor = $this->types->forClass($event::class);

        if ($event instanceof SerializableEvent) {
            $payload = $event->toPayload();
        } else {
            $payload = get_object_vars($event);
            unset($payload['eventId'], $payload['occurredAt']);
        }

        /** @var array<string, mixed> $normalized */
        $normalized = PayloadNormalizer::normalize($payload, $descriptor->type);

        return new NewEvent($descriptor->type, $normalized, $descriptor->version, $metadata, $event->eventId, $event->occurredAt);
    }

    public function toDomainEvent(StoredEvent $event): DomainEvent
    {
        $descriptor = $this->types->forType($event->eventType);
        if ($event->eventVersion !== $descriptor->version) {
            throw UnknownEventTypeException::version($event->eventType, $event->eventVersion, $descriptor->version);
        }

        $class = $descriptor->class;
        if ($descriptor->serializable && is_subclass_of($class, SerializableEvent::class)) {
            return $class::fromPayload($event->payload, $event);
        }

        $arguments = [];
        foreach ($descriptor->parameters as $parameter) {
            $path = $event->eventType.'.'.$parameter->name;
            if (array_key_exists($parameter->name, $event->payload)) {
                $arguments[$parameter->name] = self::coerce($parameter, $event->payload[$parameter->name], $path);
            } elseif ($parameter->name === 'eventId') {
                $arguments['eventId'] = $event->eventId;
            } elseif ($parameter->name === 'occurredAt') {
                $arguments['occurredAt'] = $event->occurredAt;
            } elseif (! $parameter->optional) {
                throw EventSerializationException::at($path, 'is missing from the stored payload and has no default; register an EventUpcaster that sets it.');
            }
        }

        try {
            return new $class(...$arguments);
        } catch (EventSerializationException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new EventSerializationException("The stored event [{$event->eventType}] could not be built as {$class}: {$failure->getMessage()}", 'EVENT_SERIALIZATION', 500, $failure);
        }
    }

    private static function coerce(EventParameter $parameter, mixed $value, string $path): mixed
    {
        $type = $parameter->type;
        if ($value === null) {
            return $parameter->nullable || $type === null || $type === 'mixed' ? null : throw EventSerializationException::at($path, 'is null but its parameter does not accept null.');
        }

        return match (true) {
            $type === null, $type === 'mixed' => $value,
            $type === 'int' => match (true) {
                is_int($value) => $value,
                is_float($value) && floor($value) === $value => (int) $value,
                is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
                default => throw EventSerializationException::at($path, 'is not an int.'),
            },
            $type === 'float' => is_int($value) || is_float($value) ? (float) $value : (is_string($value) && is_numeric($value) ? (float) $value : throw EventSerializationException::at($path, 'is not a number.')),
            $type === 'string' => is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : throw EventSerializationException::at($path, 'is not a string.')),
            $type === 'bool' => is_bool($value) ? $value : throw EventSerializationException::at($path, 'is not a bool.'),
            $type === 'array' => is_array($value) ? $value : throw EventSerializationException::at($path, 'is not an array.'),
            $type === DateTime::class => is_string($value) ? new DateTime($value) : throw EventSerializationException::at($path, 'is not a date string.'),
            $type === DateTimeInterface::class || is_a($type, DateTimeImmutable::class, true) => is_string($value) ? new DateTimeImmutable($value) : throw EventSerializationException::at($path, 'is not a date string.'),
            is_a($type, BackedEnum::class, true) => self::enum($type, $value, $path),
            default => throw EventSerializationException::at($path, "has the type {$type}, which the convention mapper cannot build; implement SerializableEvent on the event."),
        };
    }

    /** @param class-string<BackedEnum> $type */
    private static function enum(string $type, mixed $value, string $path): BackedEnum
    {
        if (! is_int($value) && ! is_string($value)) {
            throw EventSerializationException::at($path, "is not a {$type} value.");
        }

        return $type::tryFrom($value) ?? throw EventSerializationException::at($path, "is not a {$type} case.");
    }
}

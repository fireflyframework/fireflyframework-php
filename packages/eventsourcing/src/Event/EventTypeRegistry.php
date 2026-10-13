<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use Firefly\EventSourcing\Exception\UnknownEventTypeException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Type <-> class for every event the store may hold: the compiled #[EventType] manifest plus framework events
 * registered by hand (the orchestration provider's). A type is one class and a class one type, refused at boot
 * otherwise. Re-registering the same descriptor is harmless (a manifest and a hand registration may meet).
 */
final class EventTypeRegistry
{
    /** @var array<string, EventTypeDescriptor> */
    private array $byType = [];

    /** @var array<string, EventTypeDescriptor> */
    private array $byClass = [];

    /**
     * @param  iterable<EventTypeDescriptor>  $descriptors
     */
    public function __construct(iterable $descriptors = [])
    {
        foreach ($descriptors as $descriptor) {
            $this->register($descriptor);
        }
    }

    public function register(EventTypeDescriptor $descriptor): void
    {
        $byType = $this->byType[$descriptor->type] ?? null;
        $byClass = $this->byClass[$descriptor->class] ?? null;
        if ($byType !== null && $byType->toArray() === $descriptor->toArray()) {
            return;
        }
        if ($byType !== null) {
            throw new ConfigurationException("The event type [{$descriptor->type}] is claimed by both {$byType->class} and {$descriptor->class}.");
        }
        if ($byClass !== null) {
            throw new ConfigurationException("The event class {$descriptor->class} is registered as both [{$byClass->type}] and [{$descriptor->type}].");
        }

        $this->byType[$descriptor->type] = $descriptor;
        $this->byClass[$descriptor->class] = $descriptor;
    }

    public function forClass(string $class): EventTypeDescriptor
    {
        return $this->byClass[$class] ?? throw UnknownEventTypeException::forClass($class);
    }

    public function forType(string $type): EventTypeDescriptor
    {
        return $this->byType[$type] ?? throw UnknownEventTypeException::forType($type);
    }

    public function has(string $type): bool
    {
        return isset($this->byType[$type]);
    }

    /** @return list<EventTypeDescriptor> */
    public function all(): array
    {
        return array_values($this->byType);
    }
}

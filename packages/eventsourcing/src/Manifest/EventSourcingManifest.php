<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Manifest;

use Firefly\EventSourcing\Event\EventTypeDescriptor;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free event-sourcing source: every #[EventType] event of the application with its
 * constructor parameters. Loaded by require; written by EventSourcingManifestCompiler (php artisan firefly:cache)
 * or built in-process by EventSourcingScanner while the application is uncached.
 *
 * @phpstan-import-type EventTypeRow from EventTypeDescriptor
 */
final class EventSourcingManifest
{
    /**
     * @param  list<EventTypeDescriptor>  $eventTypes
     */
    public function __construct(private readonly array $eventTypes = []) {}

    public static function empty(): self
    {
        return new self;
    }

    /** @param array{eventTypes?: list<EventTypeRow>} $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(static fn (array $row): EventTypeDescriptor => EventTypeDescriptor::fromArray($row), $data['eventTypes'] ?? []));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Event-sourcing manifest not found at {$path}. Run php artisan firefly:cache.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Event-sourcing manifest at {$path} did not return an array.");
        }

        /** @var array{eventTypes?: list<EventTypeRow>} $data */
        return self::fromArray($data);
    }

    /** @return list<EventTypeDescriptor> */
    public function eventTypes(): array
    {
        return $this->eventTypes;
    }

    public function isEmpty(): bool
    {
        return $this->eventTypes === [];
    }

    /** @return array{eventTypes: list<EventTypeRow>} */
    public function toArray(): array
    {
        return ['eventTypes' => array_map(static fn (EventTypeDescriptor $descriptor): array => $descriptor->toArray(), $this->eventTypes)];
    }
}

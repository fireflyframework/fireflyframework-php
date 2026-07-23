<?php

declare(strict_types=1);

namespace Firefly\Eda\Listener;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free eda-listener source the EventListenerWiringPass reads at boot. Loaded via
 * require+map; the descriptors are pure arrays, so the whole manifest is a plain PHP array literal. Mirrors the
 * ScheduledManifest / RouteManifest idiom.
 *
 * @phpstan-import-type EventListenerRow from EventListenerDescriptor
 */
final class EventListenerManifest
{
    /**
     * @param  list<EventListenerDescriptor>  $listeners
     */
    public function __construct(private readonly array $listeners) {}

    /**
     * @param  array<int, EventListenerRow>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): EventListenerDescriptor => EventListenerDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Event-listener manifest not found at {$path}. Run the event-listener scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Event-listener manifest at {$path} did not return an array.");
        }

        /** @var array<int, EventListenerRow> $data */
        return self::fromArray($data);
    }

    /**
     * @return list<EventListenerDescriptor>
     */
    public function all(): array
    {
        return $this->listeners;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Messaging\Listener;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free messaging-consumer source the MessageListenerWiringPass reads at boot. Loaded via
 * require+map; descriptors are pure arrays. Mirrors EventListenerManifest / ScheduledManifest.
 *
 * @phpstan-import-type MessageListenerRow from MessageListenerDescriptor
 */
final class MessageListenerManifest
{
    /**
     * @param  list<MessageListenerDescriptor>  $listeners
     */
    public function __construct(private readonly array $listeners) {}

    /**
     * @param  array<int, MessageListenerRow>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): MessageListenerDescriptor => MessageListenerDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Message-listener manifest not found at {$path}. Run the message-listener scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Message-listener manifest at {$path} did not return an array.");
        }

        /** @var array<int, MessageListenerRow> $data */
        return self::fromArray($data);
    }

    /**
     * @return list<MessageListenerDescriptor>
     */
    public function all(): array
    {
        return $this->listeners;
    }
}

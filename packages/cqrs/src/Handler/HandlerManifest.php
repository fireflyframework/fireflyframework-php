<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Handler;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free handler source the CqrsHandlerWiringPass reads at boot (handler rows) and the
 * commandEventPublisher bean reads for routing (the #[PublishDomainEvent] destinations map). Loaded via require+map;
 * both halves are pure arrays so the whole manifest is a plain PHP array literal with two top-level keys
 * (`handlers`, `destinations`). Mirrors the ScheduledManifest / EventListenerManifest idiom.
 *
 * @phpstan-import-type HandlerRow from HandlerDescriptor
 */
final class HandlerManifest
{
    /**
     * @param  list<HandlerDescriptor>  $handlers
     * @param  array<string,string>  $destinations
     */
    public function __construct(
        private readonly array $handlers,
        private readonly array $destinations,
    ) {}

    /**
     * @param  array{handlers?: array<int, HandlerRow>, destinations?: array<string,string>}  $data
     */
    public static function fromArray(array $data): self
    {
        $handlers = array_map(
            static fn (array $row): HandlerDescriptor => HandlerDescriptor::fromArray($row),
            array_values($data['handlers'] ?? []),
        );

        return new self($handlers, $data['destinations'] ?? []);
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Handler manifest not found at {$path}. Run the handler scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Handler manifest at {$path} did not return an array.");
        }

        /** @var array{handlers?: array<int, HandlerRow>, destinations?: array<string,string>} $data */
        return self::fromArray($data);
    }

    /**
     * @return list<HandlerDescriptor>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * @return array<string,string>
     */
    public function destinations(): array
    {
        return $this->destinations;
    }
}

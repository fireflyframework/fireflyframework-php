<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

/** A payload value an event store cannot hold, or a stored payload a class cannot be built from. Named by its path. */
final class EventSerializationException extends EventSourcingException
{
    public static function at(string $path, string $why): self
    {
        return new self("{$path} {$why}", 'EVENT_SERIALIZATION');
    }
}

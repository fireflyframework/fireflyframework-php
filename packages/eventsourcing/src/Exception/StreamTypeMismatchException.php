<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

/** An aggregate id is one stream of ONE aggregate type; appending another type to it is refused before any write. */
final class StreamTypeMismatchException extends EventSourcingException
{
    public static function of(string $aggregateId, string $stored, string $given): self
    {
        return new self("The stream [{$aggregateId}] belongs to aggregate type [{$stored}], not [{$given}]: aggregate ids are unique across types.", 'EVENT_STREAM_TYPE_MISMATCH', 409);
    }
}

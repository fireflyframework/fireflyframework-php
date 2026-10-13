<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

/** EventSourcedRepository::get() found neither events nor a snapshot for the id. A 404. */
final class AggregateNotFoundException extends ResourceNotFoundException
{
    public static function of(string $aggregateType, string $id): self
    {
        return new self("No [{$aggregateType}] aggregate [{$id}] has any events.", 'AGGREGATE_NOT_FOUND');
    }
}

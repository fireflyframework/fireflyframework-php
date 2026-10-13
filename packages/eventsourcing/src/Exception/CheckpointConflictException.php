<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

/** Another runner advanced the projection's checkpoint first (its lease had expired): this batch is rolled back. */
final class CheckpointConflictException extends EventSourcingException
{
    public static function of(string $projection, int $expected): self
    {
        return new self("The checkpoint of projection [{$projection}] moved past {$expected} while this batch ran; another runner advanced it.", 'PROJECTION_CHECKPOINT_CONFLICT', 409);
    }
}

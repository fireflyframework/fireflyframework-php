<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Listeners;

/**
 * The SECOND event of the chain: NoteAudit's BEFORE_COMMIT listener publishes it for a `chain` title, so its own
 * listeners register while the BEFORE_COMMIT queue is being drained — the re-entrant case the registry must
 * fold into the same commit.
 */
final readonly class NoteIndexed
{
    public function __construct(public string $title) {}
}

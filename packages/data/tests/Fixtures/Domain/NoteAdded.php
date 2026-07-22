<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Domain;

use Firefly\Domain\DomainEvent;

/**
 * A flat readonly domain event raised by SecondaryNote — the active-record fixture used to prove the
 * connection-awareness of EloquentRepository::save()'s track-guard and DomainEventDispatcher::dispatchAfterCommit:
 * the entity's OWN connection (not the default) is what gates tracking and what the after-commit callback is
 * queued against.
 */
final readonly class NoteAdded extends DomainEvent
{
    public function __construct(public string $text)
    {
        parent::__construct();
    }
}

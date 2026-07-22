<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Domain;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * An active-record aggregate: a genuine Eloquent Model bound to the NON-default 'secondary' connection that ALSO
 * `use HasDomainEvents implements RecordsDomainEvents` — one object, persisted (the base save()'s Model arm, on
 * ITS OWN 'secondary' connection) AND tracked (its RecordsDomainEvents arm). Exists solely to prove that
 * EloquentRepository::save()'s transactionLevel() guard and DomainEventDispatcher's after-commit registration key
 * off the ENTITY'S OWN connection, not the default one (the mandatory non-default-connection coverage test).
 */
final class SecondaryNote extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $connection = 'secondary';

    protected $table = 'notes';

    public $timestamps = false;

    protected $guarded = [];

    public function addNote(string $text): void
    {
        $this->raiseEvent(new NoteAdded($text));
    }
}

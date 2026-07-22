<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Domain;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * Exercises save()'s Model + RecordsDomainEvents dual arm on a NON-default connection: persists the SecondaryNote
 * row on 'secondary' AND registers it for after-commit dispatch, keyed off the entity's OWN connection (not the
 * default one the other fixtures in this directory implicitly exercise).
 *
 * @extends EloquentRepository<SecondaryNote>
 */
#[Repository]
class SecondaryNoteRepository extends EloquentRepository
{
    protected string $model = SecondaryNote::class;
}

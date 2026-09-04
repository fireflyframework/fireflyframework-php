<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<AdminEntry>
 */
final class AdminEntryRepository extends EloquentRepository
{
    protected string $model = AdminEntry::class;
}

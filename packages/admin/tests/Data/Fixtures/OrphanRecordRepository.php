<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<OrphanRecord>
 */
final class OrphanRecordRepository extends EloquentRepository
{
    protected string $model = OrphanRecord::class;
}

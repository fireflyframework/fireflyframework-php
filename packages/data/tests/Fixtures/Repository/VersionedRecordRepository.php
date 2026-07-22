<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<VersionedRecord>
 */
#[Repository]
final class VersionedRecordRepository extends EloquentRepository
{
    protected string $model = VersionedRecord::class;
}

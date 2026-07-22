<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<SoftRecord>
 */
#[Repository]
final class SoftRecordRepository extends EloquentRepository
{
    protected string $model = SoftRecord::class;
}

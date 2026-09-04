<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<GhostRecord>
 */
final class GhostRecordRepository extends EloquentRepository
{
    protected string $model = GhostRecord::class;
}

<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures\Alt;

use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<AdminRecord>
 */
final class AdminRecordRepository extends EloquentRepository
{
    protected string $model = AdminRecord::class;
}

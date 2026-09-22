<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<AdminStamp>
 */
final class AdminStampRepository extends EloquentRepository
{
    protected string $model = AdminStamp::class;
}

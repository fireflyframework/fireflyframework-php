<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * The ordinary shape an application declares: extend EloquentRepository, set `$model`, inherit everything.
 * Nothing is overridden, so the browser is reading exactly the ports a real repository exposes.
 *
 * @extends EloquentRepository<AdminRecord>
 */
final class AdminRecordRepository extends EloquentRepository
{
    protected string $model = AdminRecord::class;
}

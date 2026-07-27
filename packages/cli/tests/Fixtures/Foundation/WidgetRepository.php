<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * A concrete #[Repository] over WidgetRecord with a derived-query method (resolved by __call, typed for PHPStan via
 * the method-tag hint). Copied from the data package's RecordRepository shape.
 *
 * @extends EloquentRepository<WidgetRecord>
 *
 * @method list<WidgetRecord> findByStatus(string $status)
 */
#[Repository]
class WidgetRepository extends EloquentRepository
{
    protected string $model = WidgetRecord::class;
}

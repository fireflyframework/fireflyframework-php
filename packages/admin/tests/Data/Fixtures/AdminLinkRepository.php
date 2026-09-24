<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * The ordinary shape again, over the join table: nothing is overridden, so the browser reads exactly the
 * ports a real repository exposes and the emptiness of `searchable()` is the schema's doing, not a stub's.
 *
 * @extends EloquentRepository<AdminLink>
 */
final class AdminLinkRepository extends EloquentRepository
{
    protected string $model = AdminLink::class;
}

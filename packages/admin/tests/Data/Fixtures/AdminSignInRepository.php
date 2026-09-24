<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * The ordinary shape once more, over the long-named table: nothing is overridden, so the widths the page
 * emits are what the LIVE schema derivation makes of those column names rather than what a stub declares.
 *
 * @extends EloquentRepository<AdminSignIn>
 */
final class AdminSignInRepository extends EloquentRepository
{
    protected string $model = AdminSignIn::class;
}

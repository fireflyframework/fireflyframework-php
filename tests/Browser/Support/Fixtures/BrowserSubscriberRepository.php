<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support\Fixtures;

use Firefly\Data\Repository\EloquentRepository;

/**
 * The ordinary shape an application declares: extend EloquentRepository, set `$model`, inherit everything —
 * including the exception translation that turns sqlite's UNIQUE failure into a DuplicateKeyException.
 *
 * @extends EloquentRepository<BrowserSubscriber>
 */
final class BrowserSubscriberRepository extends EloquentRepository
{
    protected string $model = BrowserSubscriber::class;
}

<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

/** An ordinary bean. It must never appear in the browsable-resource list. */
final class NotARepository
{
    public function handle(): string
    {
        return 'nothing to browse here';
    }
}

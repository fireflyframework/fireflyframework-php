<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

/** A backed enum, which is what an entity field of enum type actually holds after hydration. */
enum WidgetStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

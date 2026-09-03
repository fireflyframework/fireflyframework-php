<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

/** A backed enum on a body DTO — a CLASS type that `new` can never build. */
enum Currency: string
{
    case Euro = 'EUR';
    case Dollar = 'USD';
}

<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

/** A backed enum property: the case where the TYPE, not a constraint, is the whole of the contract. */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
}

<?php

declare(strict_types=1);

namespace Lumen\Domain;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
}

<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

enum CrateStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

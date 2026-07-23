<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Query\Query;

final readonly class FindWidget implements Query
{
    public function __construct(public int $id) {}
}

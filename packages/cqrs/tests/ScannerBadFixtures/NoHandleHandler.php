<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerBadFixtures;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class NoHandleHandler
{
    public function run(object $query): void {}
}

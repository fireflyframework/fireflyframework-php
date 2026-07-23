<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class FindWidgetHandler
{
    public function handle(FindWidget $query): string
    {
        return 'found:'.$query->id;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class DemoQueryHandler
{
    public function handle(DemoQuery $query): string
    {
        return 'found:'.$query->id;
    }
}

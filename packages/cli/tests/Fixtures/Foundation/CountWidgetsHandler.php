<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Cqrs\Attributes\QueryHandler;

/** Answers CountWidgets by reading the shared store the command wrote to. Mirrors the T3 App\DemoQueryHandler shape. */
#[QueryHandler]
final class CountWidgetsHandler
{
    public function __construct(private readonly WidgetStore $store) {}

    public function handle(CountWidgets $query): int
    {
        return $this->store->count();
    }
}

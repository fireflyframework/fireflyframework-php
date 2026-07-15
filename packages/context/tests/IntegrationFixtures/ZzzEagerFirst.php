<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;

/**
 * Named to sort ALPHABETICALLY AFTER AaaEagerLast (ComponentScanner scans classes sorted by
 * filename), but carries the LOWER #[Order] (5) — so resolving it FIRST proves EagerSingletonsPass
 * sorts eager singletons by the manifest's #[Order], not by scan/declaration order.
 */
#[Component]
#[Order(5)]
final class ZzzEagerFirst
{
    public function __construct(WidgetRecorder $recorder)
    {
        $recorder->record('eager:first');
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;

/**
 * Named to sort ALPHABETICALLY BEFORE ZzzEagerFirst, but carries the HIGHER #[Order] (20) — so
 * resolving it LAST (after ZzzEagerFirst) proves EagerSingletonsPass sorts eager singletons by the
 * manifest's #[Order], never by scan/declaration order (see ZzzEagerFirst's docblock).
 */
#[Component]
#[Order(20)]
final class AaaEagerLast
{
    public function __construct(WidgetRecorder $recorder)
    {
        $recorder->record('eager:last');
    }
}

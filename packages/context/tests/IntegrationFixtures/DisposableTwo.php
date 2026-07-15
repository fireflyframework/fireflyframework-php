<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Lifecycle\PreDestroy;

/**
 * Resolved (and so registered into the DisposableBeanRegistry) AFTER DisposableOne — its higher
 * #[Order] places it later in EagerSingletonsPass's sweep. ApplicationContext::close() must
 * therefore destroy it BEFORE DisposableOne (reverse registration order).
 */
#[Component]
#[Order(2)]
final class DisposableTwo
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->recorder->record('dispose:two');
    }
}

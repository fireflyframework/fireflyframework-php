<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Lifecycle\PreDestroy;

/**
 * Resolved (and so registered into the DisposableBeanRegistry) BEFORE DisposableTwo — its lower
 * #[Order] places it earlier in EagerSingletonsPass's sweep. ApplicationContext::close() must
 * therefore destroy it AFTER DisposableTwo (reverse registration order).
 */
#[Component]
#[Order(1)]
final class DisposableOne
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->recorder->record('dispose:one');
    }
}

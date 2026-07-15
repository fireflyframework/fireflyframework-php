<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Kernel\Lifecycle;

/**
 * Started SECOND (higher #[Order] than FirstInfra) by InfrastructureStartPass — must therefore be
 * STOPPED FIRST by ApplicationContext::close(): infrastructure that came up last goes down first.
 */
#[Component]
#[Order(2)]
final class SecondInfra implements Lifecycle
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    public function start(): void
    {
        $this->recorder->record('infra:start:second');
    }

    public function stop(): void
    {
        $this->recorder->record('infra:stop:second');
    }
}

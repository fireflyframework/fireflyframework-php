<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Kernel\Lifecycle;

/**
 * Started FIRST (lower #[Order]) by InfrastructureStartPass — must therefore be STOPPED LAST by
 * ApplicationContext::close(), mirroring SecondInfra's docblock.
 */
#[Component]
#[Order(1)]
final class FirstInfra implements Lifecycle
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    public function start(): void
    {
        $this->recorder->record('infra:start:first');
    }

    public function stop(): void
    {
        $this->recorder->record('infra:stop:first');
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Capstone\Fixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Eda\EventPublisher;
use Firefly\Observability\Metrics\MetricsRecorder;

/** A real application service: the container injects the CommandBus/EventPublisher/MetricsRecorder ports. */
#[Service]
final class OrderPlacer
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly EventPublisher $events,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function place(string $id): void
    {
        $this->commands->send(new PlaceOrder($id));
        $this->events->publish('orders', 'order.placed', ['id' => $id]);
        $this->metrics->increment('orders.placed', ['region' => 'eu']);
    }
}

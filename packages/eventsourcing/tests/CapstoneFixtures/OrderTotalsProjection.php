<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\Projection;

/** A read model of order totals, discovered as a bean: the projection the capstones run. */
#[Component]
final class OrderTotalsProjection implements Projection
{
    /** @var array<string, array{total: float, paidCents: int}> */
    public array $orders = [];

    public int $resets = 0;

    public function name(): string
    {
        return 'order.totals';
    }

    public function handles(): array
    {
        return ['order.placed', 'order.paid'];
    }

    public function handle(StoredEvent $event): void
    {
        $order = $this->orders[$event->aggregateId] ?? ['total' => 0.0, 'paidCents' => 0];
        if ($event->eventType === 'order.placed') {
            $total = $event->payload['total'] ?? 0;
            $order['total'] = is_int($total) || is_float($total) ? (float) $total : 0.0;
        } else {
            $amount = $event->payload['amount'] ?? 0;
            $order['paidCents'] += is_int($amount) ? $amount : 0;
        }
        $this->orders[$event->aggregateId] = $order;
    }

    public function reset(): void
    {
        $this->orders = [];
        $this->resets++;
    }
}

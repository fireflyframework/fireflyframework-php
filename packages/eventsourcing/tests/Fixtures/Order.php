<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures;

use Firefly\EventSourcing\Aggregate\EventSourcedAggregateRoot;
use Firefly\EventSourcing\Aggregate\SnapshotAware;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPaid;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPlaced;
use LogicException;

/** The aggregate the unit tests and the capstone use: placed once, paid in parts. */
final class Order extends EventSourcedAggregateRoot implements SnapshotAware
{
    private float $total = 0.0;

    private int $paidCents = 0;

    public static function aggregateType(): string
    {
        return 'order';
    }

    public static function place(string $id, float $total): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $total));

        return $order;
    }

    public function pay(int $cents): void
    {
        if ($this->isPaid()) {
            throw new LogicException('The order is already paid.');
        }
        $this->recordThat(new OrderPaid($this->id(), $cents));
    }

    public function total(): float
    {
        return $this->total;
    }

    public function paidCents(): int
    {
        return $this->paidCents;
    }

    public function isPaid(): bool
    {
        return $this->paidCents >= (int) round($this->total * 100);
    }

    protected function applyOrderPlaced(OrderPlaced $event): void
    {
        $this->total = $event->total;
    }

    protected function applyOrderPaid(OrderPaid $event): void
    {
        $this->paidCents += $event->cents;
    }

    /** @return array{total: float, paidCents: int} */
    public function snapshotState(): array
    {
        return ['total' => $this->total, 'paidCents' => $this->paidCents];
    }

    public function restoreSnapshot(array $state): void
    {
        $total = $state['total'] ?? 0.0;
        $paid = $state['paidCents'] ?? 0;
        $this->total = is_int($total) || is_float($total) ? (float) $total : 0.0;
        $this->paidCents = is_int($paid) ? $paid : 0;
    }

    public static function snapshotVersion(): int
    {
        return 1;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Ordering;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use RuntimeException;

/**
 * The transactional application service: class-level #[Transactional] wraps every public method (proxied by the
 * REAL BPP). placeOrder() persists a row + tracks the aggregate then commits (event fires after commit);
 * placeOrderAndFail() does the same then throws → the whole unit of work rolls back (no row, no event). NOT final
 * — the generated proxy extends it.
 */
#[Service]
#[Transactional]
class PlaceOrderService
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function placeOrder(string $status): Order
    {
        $order = new Order(['status' => $status, 'created_at' => self::nextCreatedAt()]);
        $order->place();
        $this->orders->save($order);

        return $order;
    }

    public function placeOrderAndFail(string $status): void
    {
        $order = new Order(['status' => $status, 'created_at' => self::nextCreatedAt()]);
        $order->place();
        $this->orders->save($order);

        throw new RuntimeException('rollback');
    }

    /** Deterministic, monotonic timestamps so findByStatusOrderByCreatedAtDesc order is stable. */
    private static function nextCreatedAt(): string
    {
        /** @var int $seconds */
        static $seconds = 0;

        return date('Y-m-d H:i:s', strtotime('2026-07-21 10:00:00') + ++$seconds);
    }
}

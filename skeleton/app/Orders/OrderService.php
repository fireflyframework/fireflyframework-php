<?php

declare(strict_types=1);

namespace App\Orders;

use Firefly\Container\Attributes\Service;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

/**
 * The order use cases, as a #[Service] bean: auto-registered as a singleton and resolved through the
 * container, so its OrderRepository dependency is autowired by constructor type. No provider, no binding,
 * no `$this->app->singleton(...)` anywhere in the application.
 *
 * WHY "NOT FOUND" IS THROWN HERE AND NOT HANDLED IN THE CONTROLLER. ResourceNotFoundException is a
 * FireflyException carrying its own HTTP status (404), error code and category, and firefly/web registers an
 * RFC-7807 renderable for the whole taxonomy at boot. Throwing it from the use case therefore produces a
 * `application/problem+json` 404 with a stable `errorCode` — the same shape every other Firefly error takes
 * — without a try/catch, an #[ExceptionHandler], or an `if (! $order) return response(..., 404)` in any of
 * the three actions that need it. The controller stays a mapping layer; the domain decides what "missing"
 * means.
 *
 * It takes and returns DOMAIN types only. The HTTP payloads live in App\Http and are translated by the
 * controller, so this class could be driven from a console command, a queued job or a CQRS handler without
 * dragging a request DTO along.
 */
#[Service]
final class OrderService
{
    public function __construct(private readonly OrderRepository $orders) {}

    /**
     * @return array{page: int, size: int, total: int, items: list<Order>}
     */
    public function page(int $page, int $size): array
    {
        return [
            'page' => $page,
            'size' => $size,
            'total' => $this->orders->count(),
            'items' => $this->orders->page($page, $size),
        ];
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    public function find(int $id): Order
    {
        return $this->orders->find($id) ?? throw $this->missing($id);
    }

    public function place(Order $order): Order
    {
        return $this->orders->save($order);
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    public function replace(int $id, Order $order): Order
    {
        return $this->orders->replace($id, $order) ?? throw $this->missing($id);
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    public function cancel(int $id): void
    {
        if (! $this->orders->delete($id)) {
            throw $this->missing($id);
        }
    }

    /**
     * One place builds the exception so the message and the error code cannot drift between the three
     * callers — a 404 whose `errorCode` varies by endpoint is one a client cannot branch on.
     */
    private function missing(int $id): ResourceNotFoundException
    {
        return new ResourceNotFoundException(sprintf('Order %d does not exist.', $id), 'ORDER_NOT_FOUND');
    }
}

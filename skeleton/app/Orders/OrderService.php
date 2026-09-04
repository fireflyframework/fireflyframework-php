<?php

declare(strict_types=1);

namespace App\Orders;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

/**
 * The order use cases, as a #[Service] bean: auto-registered as a singleton and resolved through the
 * container, so its OrderRepository dependency is autowired by constructor type. No provider, no binding,
 * no `$this->app->singleton(...)` anywhere in the application.
 *
 * WHY "NOT FOUND" IS THROWN HERE AND NOT HANDLED IN THE CONTROLLER. ResourceNotFoundException is a
 * FireflyException carrying its own HTTP status (404), error code and category, and firefly/web registers an
 * RFC-7807 renderable for the whole taxonomy at boot. Throwing it from the use case therefore produces an
 * `application/problem+json` 404 with a stable `code` — the same shape every other Firefly error takes —
 * without a try/catch, an #[ExceptionHandler], or an `if (! $order) return response(..., 404)` in any of the
 * three actions that need it. The controller stays a mapping layer; the domain decides what "missing" means.
 *
 * IT TAKES AND RETURNS DOMAIN TYPES, NOT ROWS. OrderRepository returns OrderEntity — an Eloquent model, a
 * persistence detail — and the translation to App\Orders\Order happens here, in the two private methods at
 * the bottom. That is the same split Spring has when a @Repository returns @Entity types and the service
 * layer speaks in domain objects, and it buys the property that nothing above this class knows the table
 * exists: OrderController maps HTTP to Order and back, and could not name a column if it tried.
 *
 * TOTAL IS COMPUTED, NEVER ACCEPTED. `Order::total()` derives the value from the lines; toRow() writes what
 * it computed into the column. A client that posts a `total` is ignored, because the request DTO has no such
 * field — the strongest way to say a value is not the client's to set.
 *
 * WHY THE WRITES ARE #[Transactional]. An order is two tables — the order row and its lines — so placing one
 * is two statements and replacing one is three. Without a transaction a crash between them leaves an order
 * with half its lines and a `total` that matches neither, which is not a state any reader can recover from.
 * `firefly:cache` compiles the annotation into a proxy that opens and commits around the method, so nothing
 * here calls `DB::transaction()` and nothing here has a `try/rollback` — the same trade Spring makes, and the
 * reason this class is NOT final: the generated proxy `extends` it.
 *
 * The reads are deliberately not annotated. A single SELECT needs no transaction, and wrapping one in
 * #[Transactional(readOnly: true)] to look symmetrical would buy a proxy and a round trip for nothing.
 */
#[Service]
class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderLineRepository $lines,
    ) {}

    /**
     * @return array{page: int, size: int, total: int, items: list<Order>}
     */
    public function page(int $page, int $size): array
    {
        // findPaged() runs the page fetch and the count as two queries and returns both in a Page, so the
        // "total" a client pages against is the store's, not the length of the slice it was handed.
        $found = $this->orders->findPaged(Pageable::of($page, $size));

        return [
            'page' => $found->page,
            'size' => $found->size,
            'total' => $found->total,
            'items' => array_map($this->toDomain(...), $found->items),
        ];
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    public function find(int $id): Order
    {
        return $this->toDomain($this->row($id));
    }

    #[Transactional]
    public function place(Order $order): Order
    {
        $row = new OrderEntity;
        $row->fill($this->toRow($order));
        $saved = $this->orders->save($row);
        assert($saved instanceof OrderEntity);

        $this->writeLines((int) $saved->getKey(), $order);

        return $this->toDomain($saved);
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    #[Transactional]
    public function replace(int $id, Order $order): Order
    {
        // PUT replaces the order wholesale but keeps its identity, so the existing row is refilled rather
        // than deleted and re-inserted: the id in the client's URL stays valid and so does anything holding
        // a foreign key to it. The LINES are replaced outright, because a PUT says nothing about which line
        // is which and matching them up would be inventing an identity the client never sent.
        $row = $this->row($id);
        $row->fill($this->toRow($order));
        $saved = $this->orders->save($row);
        assert($saved instanceof OrderEntity);

        $this->writeLines($id, $order);

        return $this->toDomain($saved);
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    #[Transactional]
    public function cancel(int $id): void
    {
        // deleteById() returns void — deleting something absent is not an error to Eloquent — so the
        // existence check is what turns "nothing happened" into the 404 the API promised.
        $this->row($id);
        $this->deleteLines($id);
        $this->orders->deleteById($id);
    }

    /**
     * Replace an order's lines with the ones it now carries.
     *
     * The delete-then-insert is inside the caller's transaction, which is the only thing that makes it safe:
     * on its own it is a window in which an order has no lines at all.
     */
    private function writeLines(int $orderId, Order $order): void
    {
        $this->deleteLines($orderId);

        foreach ($order->lines as $line) {
            $row = new OrderLineEntity;
            $row->fill([
                'order_id' => $orderId,
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
            ]);
            $this->lines->save($row);
        }
    }

    private function deleteLines(int $orderId): void
    {
        foreach ($this->lines->findByOrderIdOrderByIdAsc($orderId) as $line) {
            $this->lines->deleteById($line->getKey());
        }
    }

    /** @throws ResourceNotFoundException when no order carries that id */
    private function row(int $id): OrderEntity
    {
        $row = $this->orders->findById($id);

        if (! $row instanceof OrderEntity) {
            throw new ResourceNotFoundException(sprintf('Order %d does not exist.', $id), 'ORDER_NOT_FOUND');
        }

        return $row;
    }

    /** A row, and the rows it owns, as the domain understands them. */
    private function toDomain(OrderEntity $row): Order
    {
        /** @var array{street?: string, city?: string, postcode?: string, country?: string} $shipTo */
        $shipTo = is_array($row->ship_to) ? $row->ship_to : [];

        $lines = array_map(
            static fn (OrderLineEntity $line): OrderLine => new OrderLine(
                (string) $line->sku,
                (int) $line->quantity,
                (float) $line->unit_price,
            ),
            $this->lines->findByOrderIdOrderByIdAsc((int) $row->getKey()),
        );

        return new Order(
            (int) $row->getKey(),
            (string) $row->customer,
            (string) $row->email,
            new Address(
                (string) ($shipTo['street'] ?? ''),
                (string) ($shipTo['city'] ?? ''),
                (string) ($shipTo['postcode'] ?? ''),
                (string) ($shipTo['country'] ?? ''),
            ),
            array_values($lines),
        );
    }

    /**
     * A domain order as the ORDER table's columns. Its lines are not here: they are rows of their own, and
     * writeLines() owns them. The id is absent on purpose too — it belongs to the row, and `place()` must
     * not be able to choose it.
     *
     * @return array<string, mixed>
     */
    private function toRow(Order $order): array
    {
        return [
            'customer' => $order->customer,
            'email' => $order->email,
            'ship_to' => [
                'street' => $order->shipTo->street,
                'city' => $order->shipTo->city,
                'postcode' => $order->shipTo->postcode,
                'country' => $order->shipTo->country,
            ],
            'total' => $order->total(),
        ];
    }
}

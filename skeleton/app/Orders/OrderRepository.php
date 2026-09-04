<?php

declare(strict_types=1);

namespace App\Orders;

use Firefly\Container\Attributes\Repository;

/**
 * The order store, as a #[Repository] bean.
 *
 * WHY IT IS IN MEMORY AND NOT ELOQUENT. A skeleton has to work the instant `composer create-project`
 * finishes. That sequence runs `key:generate` and `firefly:cache` — it does not run `migrate` — so a sample
 * backed by EloquentRepository would answer its very first request with "no such table: orders", and the
 * first thing a new user would learn about the framework is how its 500 page looks. An array is the only
 * store that is honest about having nothing set up yet, and swapping it out is the exercise: extend
 * Firefly\Data\Repository\EloquentRepository, point `$model` at an Eloquent model, and every method below
 * disappears in favour of the inherited save/findById/findAll/count/delete plus derived queries such as
 * `findByCustomer(...)` parsed straight from the method name.
 *
 * The state survives between requests because a stereotype is registered as a SINGLETON: the component scan
 * finds #[Repository] (it specialises #[Component]) and the container resolves one instance per application.
 * That also means the data lives for exactly as long as the PHP process — a page reload under `artisan serve`
 * keeps it, a fresh process does not. That is a property of the array, not of the framework.
 *
 * Deliberately NOT final: `firefly:cache` emits a #[Transactional] proxy that `extends` the annotated class,
 * so the moment a method here gains #[Transactional] a final class would stop the compile dead.
 */
#[Repository]
class OrderRepository
{
    /** @var array<int, Order> */
    private array $orders = [];

    private int $nextId = 1;

    /** Stores a new order and returns the stored copy — the one that has an id. */
    public function save(Order $order): Order
    {
        $stored = $order->withId($this->nextId++);
        $this->orders[(int) $stored->id] = $stored;

        return $stored;
    }

    /** Replaces an existing order wholesale, keeping its id. Returns null when there is nothing to replace. */
    public function replace(int $id, Order $order): ?Order
    {
        if (! isset($this->orders[$id])) {
            return null;
        }

        return $this->orders[$id] = $order->withId($id);
    }

    public function find(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function delete(int $id): bool
    {
        if (! isset($this->orders[$id])) {
            return false;
        }

        unset($this->orders[$id]);

        return true;
    }

    public function count(): int
    {
        return count($this->orders);
    }

    /**
     * One page of orders, oldest id first. $page is 1-based because that is what a URL says.
     *
     * @return list<Order>
     */
    public function page(int $page, int $size): array
    {
        $rows = array_values($this->orders);
        usort($rows, static fn (Order $a, Order $b): int => (int) $a->id <=> (int) $b->id);

        return array_slice($rows, max(0, $page - 1) * $size, $size);
    }
}

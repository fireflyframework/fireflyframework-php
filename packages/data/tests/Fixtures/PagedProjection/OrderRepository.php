<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\PagedProjection;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\Attributes\Projection;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Slice;

/**
 * A #[Repository] whose projections are the three shapes a #[Projection] and a trailing Pageable can combine
 * into: a Page (the declared return type), a Slice (likewise), and the unpaged list a method with no Pageable
 * still gets. It lives in its own fixture directory rather than beside RecordRepository because that fixture's
 * compiled map is pinned method-for-method by the scanner tests, and because its `findByStatus` is already the
 * UNPROJECTED paged derived query the entity-graph suite asserts on.
 *
 * @extends EloquentRepository<Order>
 */
#[Repository]
class OrderRepository extends EloquentRepository
{
    protected string $model = Order::class;

    /**
     * A projection that pages: the DTO's columns are selected, the window is taken in the database, and the
     * declared `Page` return type is what makes it a Page rather than a Slice.
     *
     * @return Page<OrderSummary>
     */
    #[Projection(OrderSummary::class)]
    public function findByStatus(string $status, Pageable $pageable): Page
    {
        $page = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert($page instanceof Page);

        /** @var Page<OrderSummary> $page */
        return $page;
    }

    /**
     * The same projection sliced: size + 1 rows fetched, the extra one dropped and remembered, no count query.
     *
     * @return Slice<OrderSummary>
     */
    #[Projection(OrderSummary::class)]
    public function findByCustomer(string $customer, Pageable $pageable): Slice
    {
        $slice = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert($slice instanceof Slice);

        /** @var Slice<OrderSummary> $slice */
        return $slice;
    }

    /**
     * The control: the same projection with NO trailing Pageable, which still returns the whole list.
     *
     * @return list<OrderSummary>
     */
    #[Projection(OrderSummary::class)]
    public function findByStatusOrderByIdAsc(string $status): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<OrderSummary> $rows */
        return $rows;
    }

    /**
     * The shape the escape hatch exists for: a projection that takes a trailing Pageable and is STILL TYPED
     * `array`, because before this wave a list of every matching row is what it got back. With the key on, the
     * un-migrated call site fails loudly at the return boundary — the TypeError the feature's docblock names;
     * with the key off it keeps working for one more release. No `assert()` in the body, deliberately: what the
     * dispatch hands back is the thing under test, and the declared return type is the thing that judges it.
     *
     * @return list<OrderSummary>
     */
    #[Projection(OrderSummary::class)]
    public function findByCustomerOrderByAmountAsc(string $customer, Pageable $pageable): array
    {
        /** @var list<OrderSummary> $rows */
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());

        return $rows;
    }

    /**
     * A paged projection onto a DTO that wants a column the table lacks: the first-use check must still fire,
     * before any row is read, exactly as it does on the unpaged path.
     *
     * @return Page<OrderNickname>
     */
    #[Projection(OrderNickname::class)]
    public function findByAmountGreaterThan(int $amount, Pageable $pageable): Page
    {
        $page = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert($page instanceof Page);

        /** @var Page<OrderNickname> $page */
        return $page;
    }
}

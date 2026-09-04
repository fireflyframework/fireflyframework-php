<?php

declare(strict_types=1);

namespace App\Orders;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * The order store — and the shortest interesting class in the skeleton, because there is nothing to write.
 *
 * Extending EloquentRepository and naming a model is the whole implementation: save, findById, findAll,
 * findAllById, existsById, count, delete, deleteById, deleteAll, findPaged, findSorted and specification
 * queries are all inherited. That is the Spring Data bargain — `interface OrderRepository extends
 * JpaRepository<OrderEntity, Long> {}` — expressed the way PHP can express it.
 *
 * DERIVED QUERIES COME FROM THE METHOD NAME. `findByEmail()` below has no body worth the name: the parser
 * reads the name, splits it into a property and a comparison, and builds the query. `findByEmailAndTotalGreaterThan`,
 * `findByCustomerOrderByTotalDesc` and `countByEmail` would all work the same way, and none of them needs to
 * be declared at all — an undeclared call lands in __call and is dispatched identically. It is declared here
 * only so the signature is visible to static analysis and to your editor.
 *
 * WHY IT IS A BEAN. #[Repository] specialises #[Component], so the component scan registers this class as a
 * singleton and OrderService gets it autowired by constructor type — no provider, no binding, no
 * `$this->app->singleton(...)` anywhere in the application.
 *
 * WHY IT ALSO SHOWS UP IN THE ADMIN DASHBOARD. EloquentRepository implements CrudRepository, and the data
 * browser at /firefly/data lists every bean that does. Switch `firefly.admin.data.enabled` on and orders
 * become browsable, searchable and sortable with no further wiring — that is the whole integration.
 *
 * Deliberately NOT final: `firefly:cache` emits a #[Transactional] proxy that `extends` the annotated class,
 * so the moment a method here gains #[Transactional] a final class would stop the compile dead.
 *
 * @extends EloquentRepository<OrderEntity>
 */
#[Repository]
class OrderRepository extends EloquentRepository
{
    /** @var class-string<OrderEntity> */
    protected string $model = OrderEntity::class;

    /**
     * Every order placed by one address, newest first — parsed from this name, not from a body.
     *
     * @return list<OrderEntity>
     */
    public function findByEmailOrderByIdDesc(string $email): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<OrderEntity> $rows */
        return $rows;
    }
}

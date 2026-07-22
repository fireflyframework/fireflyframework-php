<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * Adds paging and sorting to the CRUD port. Spring overloads `findAll(Pageable)` / `findAll(Sort)`; PHP has no
 * overloading, so design §3.1 splits them into `findPaged` / `findSorted` (distinct, cleanly-typed signatures).
 *
 * @template TEntity of object
 * @template TId
 *
 * @template-extends CrudRepository<TEntity, TId>
 */
interface PagingAndSortingRepository extends CrudRepository
{
    /**
     * @return Page<TEntity>
     */
    public function findPaged(Pageable $pageable): Page;

    /**
     * @return list<TEntity>
     */
    public function findSorted(Sort $sort): array;
}

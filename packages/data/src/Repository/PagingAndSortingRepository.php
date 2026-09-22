<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * Adds paging and sorting to the CRUD port. Spring overloads `findAll(Pageable)` / `findAll(Sort)`; PHP has no
 * overloading, so design §3.1 splits them into `findPaged` / `findSorted` (distinct, cleanly-typed signatures).
 * findSlice() is the count-free twin of findPaged() — Spring returns a Slice or a Page from the same method by
 * its declared return type; PHP declares two.
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
     * A page WITHOUT a total: one row is over-fetched to learn whether a next page exists, and no count query
     * runs. Spring Data's `Slice<T> findAll(Pageable)`.
     *
     * @return Slice<TEntity>
     */
    public function findSlice(Pageable $pageable): Slice;

    /**
     * @return list<TEntity>
     */
    public function findSorted(Sort $sort): array;
}

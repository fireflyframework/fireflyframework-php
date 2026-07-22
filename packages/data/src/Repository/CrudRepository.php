<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * The persistence-agnostic CRUD port (Spring Data's `CrudRepository`). PHP has no runtime generics, so the PHP
 * signatures use `object`/`mixed`/`array`; the `@template` parameters + `@param`/`@return` docblocks give
 * PHPStan the precise entity/id types and `list<TEntity>` returns. `EloquentRepository` (Task 11) is the
 * Eloquent-backed implementation; app repositories extend that, not this interface directly.
 *
 * @template TEntity of object
 * @template TId
 */
interface CrudRepository
{
    /**
     * @param  TEntity  $entity
     * @return TEntity
     */
    public function save(object $entity): object;

    /**
     * @param  iterable<TEntity>  $entities
     * @return list<TEntity>
     */
    public function saveAll(iterable $entities): array;

    /**
     * @param  TId  $id
     * @return TEntity|null
     */
    public function findById(mixed $id): ?object;

    /**
     * @return list<TEntity>
     */
    public function findAll(): array;

    /**
     * @param  iterable<TId>  $ids
     * @return list<TEntity>
     */
    public function findAllById(iterable $ids): array;

    /**
     * @param  TId  $id
     */
    public function existsById(mixed $id): bool;

    public function count(): int;

    /**
     * @param  TEntity  $entity
     */
    public function delete(object $entity): void;

    /**
     * @param  TId  $id
     */
    public function deleteById(mixed $id): void;

    public function deleteAll(): void;
}

<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\CrudRepository;
use RuntimeException;

/**
 * A repository the catalogue knows about and the container cannot build — a constructor that wanted a
 * connection this deployment never configured. Discovery must still list it (the binding is real), and every
 * operation on it must degrade to a stated reason rather than to a 500.
 *
 * It also declines to narrow its return types, so RepositoryIntrospector can infer no entity from it: `object`
 * is what the base signature already says and carries no information. The resource is therefore named after
 * the repository class with the conventional `Repository` suffix stripped.
 *
 * @implements CrudRepository<object, mixed>
 */
final class BrokenRepository implements CrudRepository
{
    public function __construct()
    {
        throw new RuntimeException('The [reporting] connection is not configured.');
    }

    public function save(object $entity): object
    {
        return $entity;
    }

    /**
     * @param  iterable<object>  $entities
     * @return list<object>
     */
    public function saveAll(iterable $entities): array
    {
        return array_values(is_array($entities) ? $entities : iterator_to_array($entities, false));
    }

    public function findById(mixed $id): ?object
    {
        return null;
    }

    /** @return list<object> */
    public function findAll(): array
    {
        return [];
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<object>
     */
    public function findAllById(iterable $ids): array
    {
        return [];
    }

    public function existsById(mixed $id): bool
    {
        return false;
    }

    public function count(): int
    {
        return 0;
    }

    public function delete(object $entity): void {}

    public function deleteById(mixed $id): void {}

    public function deleteAll(): void {}
}

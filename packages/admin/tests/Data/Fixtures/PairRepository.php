<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\CrudRepository;
use Illuminate\Support\Facades\DB;

/**
 * A real repository over a real table whose entity has no derivable identifier.
 *
 * @implements CrudRepository<Pair, string>
 */
final class PairRepository implements CrudRepository
{
    public const string TABLE = 'pairs';

    public function save(object $entity): Pair
    {
        DB::table(self::TABLE)->updateOrInsert(['left' => $entity->left], ['right' => $entity->right]);

        return $entity;
    }

    /**
     * @param  iterable<Pair>  $entities
     * @return list<Pair>
     */
    public function saveAll(iterable $entities): array
    {
        $saved = [];
        foreach ($entities as $entity) {
            $saved[] = $this->save($entity);
        }

        return $saved;
    }

    public function findById(mixed $id): ?Pair
    {
        $row = DB::table(self::TABLE)->where('left', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<Pair> */
    public function findAll(): array
    {
        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->orderBy('left')->get()->all()));
    }

    /**
     * @param  iterable<string>  $ids
     * @return list<Pair>
     */
    public function findAllById(iterable $ids): array
    {
        $list = is_array($ids) ? array_values($ids) : iterator_to_array($ids, false);

        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->whereIn('left', $list)->get()->all()));
    }

    public function existsById(mixed $id): bool
    {
        return DB::table(self::TABLE)->where('left', '=', $id)->exists();
    }

    public function count(): int
    {
        return DB::table(self::TABLE)->count();
    }

    public function delete(object $entity): void
    {
        $this->deleteById($entity->left);
    }

    public function deleteById(mixed $id): void
    {
        DB::table(self::TABLE)->where('left', '=', $id)->delete();
    }

    public function deleteAll(): void
    {
        DB::table(self::TABLE)->delete();
    }

    private static function hydrate(object $row): Pair
    {
        $data = (array) $row;
        $left = $data['left'] ?? null;
        $right = $data['right'] ?? null;

        return new Pair(is_string($left) ? $left : '', is_string($right) ? $right : '');
    }
}

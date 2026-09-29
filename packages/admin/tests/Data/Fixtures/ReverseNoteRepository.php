<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\CrudRepository;
use Illuminate\Support\Facades\DB;

/**
 * PlainNoteRepository's twin, and the only difference between them is the one that matters: `findAll()`
 * hands its rows back in DESCENDING identifier order.
 *
 * THIS FIXTURE EXISTS TO MAKE A TIEBREAK FALSIFIABLE. `usort()` is stable in PHP 8, so a repository whose
 * `findAll()` already returns rows in identifier order hands `DataQueryEngine`'s in-PHP fallback an array
 * that ALREADY carries the answer the tiebreak would give — the ordering is then indistinguishable from an
 * ordering with no tiebreak at all, and a test written over it passes before the fix as well as after it.
 * Arriving in the reverse order is what separates the two: without the tiebreak the stable sort preserves
 * arrival, so a tied listing walks back `[6, 5, 4, 3, 2, 1]`, and with it the walk is `[1, 2, 3, 4, 5, 6]`
 * whichever way the rows came in.
 *
 * It is not a contrivance either. A repository that cannot page is free to return rows in whatever order its
 * storage found convenient — insertion order, a covering index, a cache — and it is free to find a different
 * one convenient for the request that builds page 1 and the request that builds page 2. That freedom is the
 * whole reason the tiebreak is there; this is the fixture that exercises it.
 *
 * Reads only: nothing here writes, because the only thing under test is the order rows arrive in. It stands
 * beside PlainNoteRepository over the same table rather than replacing it — the two would collide on the
 * slug `plain-note`, so a test registers exactly one of them in its own catalogue.
 *
 * @implements CrudRepository<PlainNote, int>
 */
final class ReverseNoteRepository implements CrudRepository
{
    public const string TABLE = 'admin_notes';

    /**
     * The parameter stays `object` for the contravariance reason PlainNoteRepository documents; the write
     * path is a refusal because this fixture is a reader.
     */
    public function save(object $entity): PlainNote
    {
        return $entity;
    }

    /**
     * @param  iterable<PlainNote>  $entities
     * @return list<PlainNote>
     */
    public function saveAll(iterable $entities): array
    {
        return array_values(is_array($entities) ? $entities : iterator_to_array($entities, false));
    }

    public function findById(mixed $id): ?PlainNote
    {
        $row = DB::table(self::TABLE)->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<PlainNote> */
    public function findAll(): array
    {
        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->orderByDesc('id')->get()->all()));
    }

    /**
     * @param  iterable<int>  $ids
     * @return list<PlainNote>
     */
    public function findAllById(iterable $ids): array
    {
        $list = is_array($ids) ? array_values($ids) : iterator_to_array($ids, false);

        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->whereIn('id', $list)->orderByDesc('id')->get()->all()));
    }

    public function existsById(mixed $id): bool
    {
        return DB::table(self::TABLE)->where('id', '=', $id)->exists();
    }

    public function count(): int
    {
        return DB::table(self::TABLE)->count();
    }

    public function delete(object $entity): void {}

    public function deleteById(mixed $id): void {}

    public function deleteAll(): void {}

    private static function hydrate(object $row): PlainNote
    {
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $title = $data['title'] ?? null;
        $body = $data['body'] ?? null;

        return new PlainNote(
            is_numeric($id) ? (int) $id : 0,
            is_string($title) ? $title : '',
            is_string($body) ? $body : null,
            (bool) ($data['pinned'] ?? false),
        );
    }
}

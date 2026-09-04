<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\CrudRepository;
use Illuminate\Support\Facades\DB;

/**
 * A REAL repository against the REAL sqlite connection that implements CrudRepository and NOTHING else — no
 * paging, no sorting, no specification seam. It is the shape that forces DataQueryEngine down its
 * `findAll()`-then-slice-in-PHP fallback, and it is deliberately not a mock: the fallback's whole point is
 * that it works over a genuine repository whose interface simply cannot express a limit, and a doubled
 * `findAll()` returning three hand-built objects would prove nothing about that.
 *
 * `findById()` narrows its return type to PlainNote, which is also how RepositoryIntrospector discovers the
 * entity class of a repository that has no `$model` — the same covariant-return trick the framework's own
 * lumen sample uses.
 *
 * @implements CrudRepository<PlainNote, int>
 */
final class PlainNoteRepository implements CrudRepository
{
    public const string TABLE = 'admin_notes';

    /**
     * The parameter stays `object` because PHP's contravariance rule forbids narrowing an implementation's
     * parameter below the interface's; `@implements CrudRepository<PlainNote, int>` is what binds it to
     * PlainNote for the type checker, which is why no instanceof guard appears here.
     */
    public function save(object $entity): PlainNote
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['id' => $entity->id()],
            ['title' => $entity->title, 'body' => $entity->body, 'pinned' => $entity->pinned],
        );

        return $entity;
    }

    /**
     * @param  iterable<PlainNote>  $entities
     * @return list<PlainNote>
     */
    public function saveAll(iterable $entities): array
    {
        $saved = [];
        foreach ($entities as $entity) {
            $saved[] = $this->save($entity);
        }

        return $saved;
    }

    public function findById(mixed $id): ?PlainNote
    {
        $row = DB::table(self::TABLE)->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<PlainNote> */
    public function findAll(): array
    {
        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->orderBy('id')->get()->all()));
    }

    /**
     * @param  iterable<int>  $ids
     * @return list<PlainNote>
     */
    public function findAllById(iterable $ids): array
    {
        $list = is_array($ids) ? array_values($ids) : iterator_to_array($ids, false);

        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->whereIn('id', $list)->orderBy('id')->get()->all()));
    }

    public function existsById(mixed $id): bool
    {
        return DB::table(self::TABLE)->where('id', '=', $id)->exists();
    }

    public function count(): int
    {
        return DB::table(self::TABLE)->count();
    }

    public function delete(object $entity): void
    {
        $this->deleteById($entity->id());
    }

    public function deleteById(mixed $id): void
    {
        DB::table(self::TABLE)->where('id', '=', $id)->delete();
    }

    public function deleteAll(): void
    {
        DB::table(self::TABLE)->delete();
    }

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

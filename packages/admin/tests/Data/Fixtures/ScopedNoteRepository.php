<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Firefly\Data\Repository\CrudRepository;
use Illuminate\Support\Facades\DB;

/**
 * A real repository whose DELETE is scoped and whose reads are not — an archive policy that only removes
 * pinned notes. It is the ordinary shape (a soft-delete scope, a tenant guard, an override that swallows the
 * call) in which `deleteById()` returns void having done nothing at all, and the reason DataBrowser::delete()
 * verifies the removal with `existsById()` instead of trusting it.
 *
 * @implements CrudRepository<PlainNote, int>
 */
final class ScopedNoteRepository implements CrudRepository
{
    public const string TABLE = 'admin_notes';

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

        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->whereIn('id', $list)->get()->all()));
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

    /** Only a pinned note is archivable; anything else is silently left where it is. */
    public function deleteById(mixed $id): void
    {
        DB::table(self::TABLE)->where('id', '=', $id)->where('pinned', '=', 1)->delete();
    }

    public function deleteAll(): void
    {
        DB::table(self::TABLE)->where('pinned', '=', 1)->delete();
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

<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use DateTimeImmutable;
use Firefly\Data\Repository\CrudRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * A real sqlite-backed CrudRepository whose entity carries typed PHP values rather than column scalars —
 * the shape that exercises every branch of the projector's normalisation.
 *
 * @implements CrudRepository<Widget, string>
 */
final class WidgetRepository implements CrudRepository
{
    public const string TABLE = 'widgets';

    public function save(object $entity): Widget
    {
        DB::table(self::TABLE)->updateOrInsert(['uuid' => $entity->uuid], [
            'name' => $entity->name,
            'occurred_at' => $entity->occurredAt->format('Y-m-d H:i:s'),
            'status' => $entity->status->value,
            'tags' => (string) json_encode($entity->tags),
            'price' => (string) $entity->price,
        ]);

        return $entity;
    }

    /**
     * @param  iterable<Widget>  $entities
     * @return list<Widget>
     */
    public function saveAll(iterable $entities): array
    {
        $saved = [];
        foreach ($entities as $entity) {
            $saved[] = $this->save($entity);
        }

        return $saved;
    }

    public function findById(mixed $id): ?Widget
    {
        $row = DB::table(self::TABLE)->where('uuid', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<Widget> */
    public function findAll(): array
    {
        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->orderBy('uuid')->get()->all()));
    }

    /**
     * @param  iterable<string>  $ids
     * @return list<Widget>
     */
    public function findAllById(iterable $ids): array
    {
        $list = is_array($ids) ? array_values($ids) : iterator_to_array($ids, false);

        return array_values(array_map(self::hydrate(...), DB::table(self::TABLE)->whereIn('uuid', $list)->get()->all()));
    }

    public function existsById(mixed $id): bool
    {
        return DB::table(self::TABLE)->where('uuid', '=', $id)->exists();
    }

    public function count(): int
    {
        return DB::table(self::TABLE)->count();
    }

    public function delete(object $entity): void
    {
        $this->deleteById($entity->uuid);
    }

    public function deleteById(mixed $id): void
    {
        DB::table(self::TABLE)->where('uuid', '=', $id)->delete();
    }

    public function deleteAll(): void
    {
        DB::table(self::TABLE)->delete();
    }

    private static function hydrate(object $row): Widget
    {
        $data = (array) $row;

        $tags = json_decode(self::text($data, 'tags'), true);
        $price = explode(' ', self::text($data, 'price'));

        return new Widget(
            self::text($data, 'uuid'),
            self::text($data, 'name'),
            new DateTimeImmutable(self::text($data, 'occurred_at')),
            WidgetStatus::from(self::text($data, 'status')),
            is_array($tags) ? array_values(array_map(static fn (mixed $t): string => is_string($t) ? $t : '', $tags)) : [],
            new Money($price[0], $price[1] ?? 'EUR'),
            new stdClass,
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new RuntimeException("Column [{$key}] is not text.");
    }
}

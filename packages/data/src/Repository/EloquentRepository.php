<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

use Firefly\Data\Transaction\TransactionalManifest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent-backed base for a #[Repository]. A concrete repository sets `protected string $model = X::class;`
 * and inherits the full Crud/PagingAndSorting contract over `Model::query()`. Page/Pageable/Sort are mapped to
 * `skip()`/`take()`/`orderBy()` + a count query at the edge, so callers never see Eloquent. The optional
 * TransactionalManifest is consumed only by the derived-query / #[Query] dispatch (Task 13); CRUD and paging work
 * with a null manifest. Reflection-free: everything goes through the Eloquent Builder — no runtime class introspection.
 * The query-building seam (`query()`/`applySort()`) works over the model's own bound `Builder<Model>`; every result
 * that leaves the class is narrowed back to `TModel` with an `instanceof $this->model` check (`narrow()`), which is
 * how the class-string held on `$model` re-attaches the entity's own template to what the Eloquent Builder returns.
 *
 * @template TModel of Model
 *
 * @implements PagingAndSortingRepository<TModel, mixed>
 */
abstract class EloquentRepository implements PagingAndSortingRepository
{
    /** @var class-string<TModel> */
    protected string $model;

    public function __construct(protected readonly ?TransactionalManifest $manifest = null) {}

    /**
     * @param  TModel  $entity
     * @return TModel
     */
    public function save(object $entity): object
    {
        $entity->save();

        return $entity;
    }

    /**
     * @param  iterable<TModel>  $entities
     * @return list<TModel>
     */
    public function saveAll(iterable $entities): array
    {
        $saved = [];
        foreach ($entities as $entity) {
            $saved[] = $this->save($entity);
        }

        return $saved;
    }

    /**
     * @return TModel|null
     */
    public function findById(mixed $id): ?object
    {
        $found = $this->query()->find($id);

        return $found instanceof $this->model ? $found : null;
    }

    /**
     * @return list<TModel>
     */
    public function findAll(): array
    {
        return $this->narrow($this->query()->get()->all());
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<TModel>
     */
    public function findAllById(iterable $ids): array
    {
        $ids = is_array($ids) ? array_values($ids) : iterator_to_array($ids, false);

        return $this->narrow($this->query()->whereIn($this->keyName(), $ids)->get()->all());
    }

    public function existsById(mixed $id): bool
    {
        return $this->query()->where($this->keyName(), '=', $id)->exists();
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @param  TModel  $entity
     */
    public function delete(object $entity): void
    {
        $entity->delete();
    }

    public function deleteById(mixed $id): void
    {
        $this->findById($id)?->delete();
    }

    public function deleteAll(): void
    {
        $this->query()->delete();
    }

    /**
     * @return Page<TModel>
     */
    public function findPaged(Pageable $pageable): Page
    {
        $total = $this->query()->count();

        $items = $this->applySort($this->query(), $pageable->sort)
            ->skip($pageable->offset())
            ->take($pageable->size)
            ->get()
            ->all();

        return new Page($this->narrow($items), $total, $pageable->page, $pageable->size);
    }

    /**
     * @return list<TModel>
     */
    public function findSorted(Sort $sort): array
    {
        return $this->narrow($this->applySort($this->query(), $sort)->get()->all());
    }

    /**
     * @return Builder<Model>
     */
    protected function query(): Builder
    {
        return ($this->model)::query();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applySort(Builder $query, ?Sort $sort): Builder
    {
        foreach ($sort->orders ?? [] as $order) {
            $query->orderBy($order->property, $order->direction->value);
        }

        return $query;
    }

    protected function keyName(): string
    {
        return (new $this->model)->getKeyName();
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<TModel>
     */
    private function narrow(iterable $models): array
    {
        $narrowed = [];
        foreach ($models as $model) {
            if ($model instanceof $this->model) {
                $narrowed[] = $model;
            }
        }

        return $narrowed;
    }
}

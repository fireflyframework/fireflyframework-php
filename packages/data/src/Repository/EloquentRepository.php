<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

use BadMethodCallException;
use Firefly\Data\Repository\Query\DerivedQueryParser;
use Firefly\Data\Repository\Query\ParsedQuery;
use Firefly\Data\Repository\Query\Predicate;
use Firefly\Data\Repository\Specification\Specification;
use Firefly\Data\Transaction\TransactionalManifest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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

    /** @var array<string, ParsedQuery> */
    private static array $parsedCache = [];

    /**
     * The dynamic entry for UNDECLARED derived-query methods (declared #[Query] methods call dispatchQuery directly).
     *
     * @param  list<mixed>  $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->dispatchQuery($method, $args);
    }

    /**
     * The single dispatcher shared by __call (derived) and declared #[Query] method bodies: explicit SQL from the
     * manifest wins; otherwise the method name is parsed into a derived query and drives the Builder.
     *
     * @param  list<mixed>  $args
     */
    protected function dispatchQuery(string $method, array $args): mixed
    {
        $queries = $this->manifest?->queriesFor(static::class) ?? [];
        if (isset($queries[$method])) {
            return $this->runExplicitQuery($queries[$method]['sql'], $args);
        }

        try {
            $parsed = self::$parsedCache[$method] ??= DerivedQueryParser::parse($method);
        } catch (InvalidArgumentException $e) {
            throw new BadMethodCallException(
                sprintf('%s::%s() is neither a #[Query] method nor a parseable derived query.', static::class, $method),
                0,
                $e,
            );
        }

        return $this->driveDerivedQuery($parsed, $args);
    }

    /**
     * Run explicit #[Query] SQL. Named `:placeholders` are rewritten to positional `?` in appearance order and the
     * method arguments bind positionally (name-based binding needs param metadata — deferred; positional works now).
     *
     * @param  list<mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected function runExplicitQuery(string $sql, array $args): array
    {
        $normalized = (string) preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '?', $sql);
        $rows = (new $this->model)->getConnection()->select($normalized, $args);

        return array_values(array_map(self::rowToArray(...), $rows));
    }

    /**
     * Turn a raw DB row (a stdClass from the default PDO fetch) into a column => value map with string keys.
     *
     * @return array<string, mixed>
     */
    private static function rowToArray(mixed $row): array
    {
        $columns = [];
        if (is_object($row)) {
            foreach (get_object_vars($row) as $name => $value) {
                $columns[(string) $name] = $value;
            }
        }

        return $columns;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function driveDerivedQuery(ParsedQuery $parsed, array $args): mixed
    {
        $query = $this->query();
        $cursor = 0;

        foreach ($parsed->predicates as $index => $predicate) {
            $boolean = $index === 0 ? 'and' : strtolower($parsed->connectors[$index - 1]);
            $cursor = $this->applyPredicate($query, $predicate, $args, $cursor, $boolean);
        }

        foreach ($parsed->orders as $order) {
            $query->orderBy($order->field, $order->dir);
        }

        if ($parsed->distinct) {
            $query->distinct();
        }

        if ($parsed->top !== null) {
            $query->limit($parsed->top);
        }

        return match ($parsed->prefix) {
            'count' => $query->count(),
            'exists' => $query->exists(),
            'delete' => $query->delete(),
            'find' => $parsed->top === 1
                ? $query->first()
                : $this->narrow($query->get()->all()),
        };
    }

    /**
     * Bind a predicate to the Builder and return the advanced argument cursor. IgnoreCase routes the equality /
     * LIKE family through `LOWER(col) op LOWER(?)`; the column is safe to interpolate because DerivedQueryParser
     * has already validated every derived field against `^[a-z_][a-z0-9_]*$` (a bare identifier), rejecting any
     * crafted method name reaching the public __call before it ever gets here.
     *
     * @param  Builder<Model>  $query
     * @param  list<mixed>  $args
     */
    private function applyPredicate(Builder $query, Predicate $predicate, array $args, int $cursor, string $boolean): int
    {
        $column = $predicate->field;

        if ($predicate->ignoreCase
            && in_array($predicate->op, ['Equals', 'Not', 'Like', 'NotLike', 'Containing', 'StartingWith', 'EndingWith'], true)
        ) {
            $sqlOp = match ($predicate->op) {
                'Not' => '!=',
                'NotLike' => 'not like',
                'Like', 'Containing', 'StartingWith', 'EndingWith' => 'like',
                default => '=',
            };
            // $column is a parser-validated bare identifier (^[a-z_][a-z0-9_]*$; see DerivedQueryParser::toSnake),
            // so the LOWER(col) fragment is provably injection-free. whereRaw types $sql as literal-string and a
            // runtime-built string can never satisfy that, so the narrowly-scoped suppression stays.
            // @phpstan-ignore argument.type
            $query->whereRaw("LOWER({$column}) {$sqlOp} LOWER(?)", [self::likeValue($predicate->op, $args[$cursor])], $boolean);

            return $cursor + 1;
        }

        switch ($predicate->op) {
            case 'Between':
                $query->whereBetween($column, [$args[$cursor], $args[$cursor + 1]], $boolean);

                return $cursor + 2;
            case 'In':
                $query->whereIn($column, self::toList($args[$cursor]), $boolean);

                return $cursor + 1;
            case 'NotIn':
                $query->whereIn($column, self::toList($args[$cursor]), $boolean, true);

                return $cursor + 1;
            case 'IsNull':
                $query->whereNull($column, $boolean);

                return $cursor;
            case 'IsNotNull':
                $query->whereNotNull($column, $boolean);

                return $cursor;
            case 'True':
                $query->where($column, '=', true, $boolean);

                return $cursor;
            case 'False':
                $query->where($column, '=', false, $boolean);

                return $cursor;
            case 'Like':
                $query->where($column, 'like', $args[$cursor], $boolean);

                return $cursor + 1;
            case 'NotLike':
                $query->where($column, 'not like', $args[$cursor], $boolean);

                return $cursor + 1;
            case 'Containing':
                $query->where($column, 'like', self::likeValue('Containing', $args[$cursor]), $boolean);

                return $cursor + 1;
            case 'StartingWith':
                $query->where($column, 'like', self::likeValue('StartingWith', $args[$cursor]), $boolean);

                return $cursor + 1;
            case 'EndingWith':
                $query->where($column, 'like', self::likeValue('EndingWith', $args[$cursor]), $boolean);

                return $cursor + 1;
            case 'GreaterThan':
                $query->where($column, '>', $args[$cursor], $boolean);

                return $cursor + 1;
            case 'GreaterThanEqual':
                $query->where($column, '>=', $args[$cursor], $boolean);

                return $cursor + 1;
            case 'LessThan':
                $query->where($column, '<', $args[$cursor], $boolean);

                return $cursor + 1;
            case 'LessThanEqual':
                $query->where($column, '<=', $args[$cursor], $boolean);

                return $cursor + 1;
            case 'Not':
                $query->where($column, '!=', $args[$cursor], $boolean);

                return $cursor + 1;
            default: // Equals
                $query->where($column, '=', $args[$cursor], $boolean);

                return $cursor + 1;
        }
    }

    /**
     * @return list<mixed>
     */
    private static function toList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }

    private static function likeValue(string $op, mixed $value): string
    {
        $string = self::stringify($value);

        return match ($op) {
            'Containing' => '%'.$string.'%',
            'StartingWith' => $string.'%',
            'EndingWith' => '%'.$string,
            default => $string,
        };
    }

    /**
     * Coerce a bound predicate argument to a string for a LIKE / IgnoreCase comparison. A non-scalar,
     * non-Stringable argument is a caller error (the derived-query grammar expects scalar bindings).
     */
    private static function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('A LIKE/IgnoreCase predicate argument must be scalar or Stringable.');
    }

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
     * The Specification's TModel is bound to Model (not TModel), matching the internal builder seam
     * (`query()`/`applySort()` are likewise typed against `Builder<Model>`) — narrowed at the terminal via
     * `narrow()`, same as every other list-returning method on this class.
     *
     * @param  Specification<Model>  $specification
     * @return list<TModel>
     */
    public function findBySpecification(Specification $specification): array
    {
        return $this->narrow($specification->toBuilder($this->query())->get()->all());
    }

    /**
     * Paged variant: the specification is applied to a fresh builder twice — once for the total count, once for the
     * sorted slice — for the same `Specification<Model>` / `narrow()`-at-terminal reason as findBySpecification.
     *
     * @param  Specification<Model>  $specification
     * @return Page<TModel>
     */
    public function findBySpecificationPaged(Specification $specification, Pageable $pageable): Page
    {
        $total = $specification->toBuilder($this->query())->count();

        $items = $this->applySort($specification->toBuilder($this->query()), $pageable->sort)
            ->skip($pageable->offset())
            ->take($pageable->size)
            ->get()
            ->all();

        return new Page($this->narrow($items), $total, $pageable->page, $pageable->size);
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

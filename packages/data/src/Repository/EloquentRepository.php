<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

use BadMethodCallException;
use Closure;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Repository\Example\Example;
use Firefly\Data\Repository\Locking\LockMode;
use Firefly\Data\Repository\Projection\ProjectionHydrator;
use Firefly\Data\Repository\Query\DerivedQueryParser;
use Firefly\Data\Repository\Query\ParsedQuery;
use Firefly\Data\Repository\Query\Predicate;
use Firefly\Data\Repository\Specification\Specification;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Domain\RecordsDomainEvents;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Kernel\Exception\Infrastructure\EmptyResultDataAccessException;
use Firefly\Kernel\Exception\Infrastructure\IncorrectResultSizeDataAccessException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The Eloquent-backed base for a #[Repository]. A concrete repository sets `protected string $model = X::class;`
 * and inherits the full Crud/PagingAndSorting contract over `Model::query()`. Page/Pageable/Sort are mapped to
 * `skip()`/`take()`/`orderBy()` + a count query at the edge, so callers never see Eloquent. The optional
 * TransactionalManifest is consumed by the derived-query / #[Query] dispatch; CRUD and paging work with a null
 * manifest. Reflection-free: everything goes through the Eloquent Builder — no runtime class introspection.
 * The query-building seam (`query()`/`applySort()`) works over the model's own bound `Builder<Model>`; every result
 * that leaves the class is narrowed back to `TModel` with an `instanceof $this->model` check (`narrow()`), which is
 * how the class-string held on `$model` re-attaches the entity's own template to what the Eloquent Builder returns.
 *
 * EVERY PUBLIC METHOD RUNS THROUGH translating(): a driver failure leaves this class as a member of the kernel's
 * DataAccessException family (DuplicateKeyException, BadSqlGrammarException, ...) with the QueryException as
 * `previous` — Spring's PersistenceExceptionTranslator applied at the repository. A null $translator is the
 * enabled default; DataAutoConfiguration injects the one built from firefly.data.exception-translation.enabled.
 *
 * repositoryClass() is what manifest lookups key on, not static::class: a #[Repository] that is also
 * #[Transactional] runs as its generated `__FireflyTransactionalProxy` subclass, and the manifest knows the
 * declared class.
 *
 * @phpstan-import-type RepositoryMethodRow from TransactionalManifest
 *
 * @template TModel of Model
 *
 * @implements PagingAndSortingRepository<TModel, mixed>
 */
abstract class EloquentRepository implements PagingAndSortingRepository
{
    private const string PROXY_SUFFIX = '__FireflyTransactionalProxy';

    /** @var class-string<TModel> */
    protected string $model;

    private readonly PersistenceExceptionTranslator $translator;

    public function __construct(
        protected readonly ?TransactionalManifest $manifest = null,
        protected readonly ?AggregateTracker $tracker = null,
        ?PersistenceExceptionTranslator $translator = null,
    ) {
        $this->translator = $translator ?? new PersistenceExceptionTranslator;
    }

    /** @var array<string, ParsedQuery> */
    private static array $parsedCache = [];

    /**
     * The model's table columns, read once per model class per process for #[Projection]'s first-use check —
     * the same one-time schema read, cached the same way, that Eloquent's own guarded-attribute check makes
     * (Model::$guardableColumns).
     *
     * @var array<class-string, list<string>>
     */
    private static array $projectableColumns = [];

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
     * The single dispatcher shared by __call (derived) and declared method bodies: explicit SQL from the manifest
     * wins; otherwise the method name is parsed into a derived query and drives the Builder. The method's
     * repository row (#[Modifying]/#[Projection]/#[Lock]/#[EntityGraph]/Slice, compiled by the scanner) rides
     * along so either path can honour it.
     *
     * @param  list<mixed>  $args
     */
    protected function dispatchQuery(string $method, array $args): mixed
    {
        return $this->translating(function () use ($method, $args): mixed {
            $row = $this->methodRow($method);
            $queries = $this->manifest?->queriesFor($this->repositoryClass()) ?? [];
            if (isset($queries[$method])) {
                return $this->runExplicitQuery($queries[$method]['sql'], $args, $row);
            }

            try {
                $parsed = self::$parsedCache[$method] ??= DerivedQueryParser::parse($method);
            } catch (InvalidArgumentException $e) {
                throw new BadMethodCallException(
                    sprintf('%s::%s() is neither a #[Query] method nor a parseable derived query.', $this->repositoryClass(), $method),
                    0,
                    $e,
                );
            }

            return $this->driveDerivedQuery($parsed, $args, $row);
        });
    }

    /**
     * The compiled repository row for one of THIS repository's declared methods, or null for a method that
     * carries no attribute and declares no Slice/Page return (every undeclared __call method).
     *
     * @return RepositoryMethodRow|null
     */
    protected function methodRow(string $method): ?array
    {
        return $this->manifest?->repositoryMethod($this->repositoryClass(), $method);
    }

    /**
     * Run explicit #[Query] SQL. Named `:placeholders` are rewritten to positional `?` in appearance order and the
     * method arguments bind positionally (name-based binding needs param metadata — deferred; positional works now).
     * A #[Modifying] row runs the SQL as a STATEMENT — Connection::affectingStatement(), the affected-row count
     * back — and, unless the attribute says otherwise, only inside an open transaction on the model's connection:
     * an update that auto-commits under a caller who believed it was part of a unit of work is the bug Spring's
     * "@Modifying needs @Transactional" rule exists to prevent. A #[Projection] row hands every fetched row to the
     * ProjectionHydrator — the SQL owns its select list, so a column the DTO needs and the SQL forgot is the
     * hydrator's ConfigurationException on the first row.
     *
     * @param  list<mixed>  $args
     * @param  RepositoryMethodRow|null  $row
     * @return list<array<string, mixed>>|list<object>|int
     */
    protected function runExplicitQuery(string $sql, array $args, ?array $row = null): array|int
    {
        $normalized = (string) preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '?', $sql);
        $connection = (new $this->model)->getConnection();

        $modifying = $row['modifying'] ?? null;
        if ($modifying !== null) {
            if ($modifying['requiresTransaction'] && $connection->transactionLevel() === 0) {
                throw new TransactionRequiredException(
                    'A #[Modifying] query requires an active transaction; declare #[Modifying(requiresTransaction: false)] to run it outside one.',
                );
            }

            return $connection->affectingStatement($normalized, $args);
        }

        $rows = array_values(array_map(self::rowToArray(...), $connection->select($normalized, $args)));

        $projection = $row['projection'] ?? null;
        if ($projection === null) {
            return $rows;
        }

        $hydrator = new ProjectionHydrator($projection);

        return array_map($hydrator->hydrate(...), $rows);
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
     * @param  RepositoryMethodRow|null  $row
     */
    protected function driveDerivedQuery(ParsedQuery $parsed, array $args, ?array $row = null): mixed
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

        $lock = $row['lock'] ?? null;
        if ($lock !== null && $parsed->prefix === 'find') {
            $this->applyLock($query, LockMode::from($lock));
        }

        $projection = $row['projection'] ?? null;
        if ($projection !== null && $parsed->prefix === 'find') {
            return $this->projectDerived($query, $parsed, new ProjectionHydrator($projection));
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
     * A derived `find` under #[Projection]: the SELECT list is the DTO's columns (or the attribute's), and the
     * rows are read from the base query — never hydrated into the model — so the hydrator sees the driver's raw
     * values (no casts, no accessors) exactly as a #[Query] projection does. `count`/`exists`/`delete` ignore a
     * projection. The columns are checked against the table FIRST (see assertProjectable()):
     * a column the DTO wants and the table lacks must be a ConfigurationException that names it, and the driver
     * cannot be trusted to say so — MySQL rejects the SELECT with a grammar error, while sqlite silently reads a
     * double-quoted unknown identifier as a string literal and hands back a row full of the column's own name.
     *
     * @param  Builder<Model>  $query
     * @return object|list<object>|null
     */
    private function projectDerived(Builder $query, ParsedQuery $parsed, ProjectionHydrator $hydrator): object|array|null
    {
        $this->assertProjectable($hydrator);

        $base = $query->select($hydrator->columns())->toBase();

        if ($parsed->top === 1) {
            $first = $base->first();

            return $first === null ? null : $hydrator->hydrate(self::rowToArray($first));
        }

        $projected = [];
        foreach ($base->get() as $row) {
            $projected[] = $hydrator->hydrate(self::rowToArray($row));
        }

        return $projected;
    }

    /**
     * #[Projection]'s first-use check: every bare-identifier column the DTO selects must be a column of the
     * model's table, or the ConfigurationException names the missing ones, the table and what it does have. The
     * listing is read once per model class per process and cached (Eloquent's Model::isGuardableColumn() makes
     * the identical read, cached the identical way, for the identical reason); a schema that cannot be read — an
     * empty listing, a driver without introspection — is not cached, skips the check and leaves the verdict to
     * the driver. Qualified or aliased entries
     * in a `columns:` list (`records.id`, `amount as total`) are the driver's to judge as well.
     */
    private function assertProjectable(ProjectionHydrator $hydrator): void
    {
        $known = self::$projectableColumns[$this->model] ?? $this->columnListing();
        if ($known === []) {
            return;
        }
        self::$projectableColumns[$this->model] = $known;

        $missing = [];
        foreach ($hydrator->columns() as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1 && ! in_array(strtolower($column), $known, true)) {
                $missing[] = $column;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new ConfigurationException(sprintf(
            'Projection [%s] on %s selects column%s [%s], which table [%s] does not have (it has [%s]).',
            $hydrator->dto(),
            class_basename($this->model),
            count($missing) === 1 ? '' : 's',
            implode(', ', $missing),
            (new $this->model)->getTable(),
            implode(', ', $known),
        ));
    }

    /**
     * The model table's column names, lower-cased for a case-insensitive comparison; empty when the schema cannot
     * be read.
     *
     * @return list<string>
     */
    private function columnListing(): array
    {
        try {
            $model = new $this->model;
            $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
        } catch (Throwable) {
            return [];
        }

        return array_map(strtolower(...), $columns);
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
     * Persist $entity if it is an Eloquent Model, and register it for after-commit domain-event dispatch if it
     * records domain events while a transaction is active ON ITS OWN CONNECTION (the one it is written on). A
     * Model that also `use HasDomainEvents implements RecordsDomainEvents` hits BOTH arms — one object, persisted
     * AND tracked. Restores the simple TEntity in/out signature (no conditional/union widening): the local
     * `@template TEntity of object` widens the port's `save(TModel): TModel` so a pure aggregate can also flow
     * through, while the return stays exactly the passed type — PHPStan-max clean.
     *
     * @template TEntity of object
     *
     * @param  TEntity  $entity
     * @return TEntity
     */
    public function save(object $entity): object
    {
        return $this->translating(function () use ($entity): object {
            if ($entity instanceof Model) {
                $entity->save();
            }

            if ($entity instanceof RecordsDomainEvents
                && $this->tracker !== null
                && $this->connectionFor($entity)->transactionLevel() > 0) {
                $this->tracker->track($entity);
            }

            return $entity;
        });
    }

    private function connectionFor(object $entity): Connection
    {
        return $entity instanceof Model ? $entity->getConnection() : DB::connection();
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
        return $this->translating(function () use ($id): ?object {
            $found = $this->query()->find($id);

            return $found instanceof $this->model ? $found : null;
        });
    }

    /**
     * findById() that insists: Spring Data's `findById(id).orElseThrow()` shape. The 404 EmptyResultDataAccessException
     * names the entity and the id, which is exactly what a controller wants to hand to problem+json.
     *
     * @return TModel
     */
    public function getById(mixed $id): object
    {
        return $this->findById($id) ?? throw new EmptyResultDataAccessException(
            sprintf('No %s with id [%s].', class_basename($this->model), is_scalar($id) ? (string) $id : get_debug_type($id)),
        );
    }

    /**
     * findById() under `SELECT ... FOR UPDATE` — the programmatic twin of #[Lock(LockMode::PESSIMISTIC_WRITE)].
     * Like the attribute it refuses to run outside a transaction: the lock is released when the transaction
     * ends, so outside one it would guard nothing. Spring Data's findById with LockModeType.PESSIMISTIC_WRITE.
     *
     * @return TModel|null
     */
    public function findByIdForUpdate(mixed $id): ?object
    {
        return $this->translating(function () use ($id): ?object {
            $query = $this->query();
            $this->applyLock($query, LockMode::PESSIMISTIC_WRITE);
            $found = $query->find($id);

            return $found instanceof $this->model ? $found : null;
        });
    }

    /**
     * The transaction check and the builder call behind #[Lock] and findByIdForUpdate(): PESSIMISTIC_WRITE is
     * lockForUpdate() (`FOR UPDATE`), PESSIMISTIC_READ is sharedLock() (`FOR SHARE` / `LOCK IN SHARE MODE`), each
     * spelled by the connection's own grammar — sqlite has no row locks and its grammar compiles the clause to
     * nothing, so the read simply succeeds there. The transaction is checked on the MODEL's connection, the one
     * the statement will run on, exactly as #[Modifying] does.
     *
     * @param  Builder<Model>  $query
     */
    private function applyLock(Builder $query, LockMode $mode): void
    {
        if ((new $this->model)->getConnection()->transactionLevel() === 0) {
            throw new TransactionRequiredException(sprintf(
                '#[Lock(%s)] needs an active transaction: a row lock is released when the transaction ends, so outside one it would guard nothing.',
                $mode->name,
            ));
        }

        if ($mode === LockMode::PESSIMISTIC_WRITE) {
            $query->lockForUpdate();
        } else {
            $query->sharedLock();
        }
    }

    /**
     * @return list<TModel>
     */
    public function findAll(): array
    {
        return $this->translating(fn (): array => $this->narrow($this->query()->get()->all()));
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<TModel>
     */
    public function findAllById(iterable $ids): array
    {
        $ids = is_array($ids) ? array_values($ids) : iterator_to_array($ids, false);

        return $this->translating(fn (): array => $this->narrow($this->query()->whereIn($this->keyName(), $ids)->get()->all()));
    }

    public function existsById(mixed $id): bool
    {
        return $this->translating(fn (): bool => $this->query()->where($this->keyName(), '=', $id)->exists());
    }

    public function count(): int
    {
        return $this->translating(fn (): int => $this->query()->count());
    }

    /**
     * @param  TModel  $entity
     */
    public function delete(object $entity): void
    {
        $this->translating(function () use ($entity): void {
            $entity->delete();
        });
    }

    public function deleteById(mixed $id): void
    {
        $this->translating(function () use ($id): void {
            $this->findById($id)?->delete();
        });
    }

    public function deleteAll(): void
    {
        $this->translating(function (): void {
            $this->query()->delete();
        });
    }

    /**
     * @return Page<TModel>
     */
    public function findPaged(Pageable $pageable): Page
    {
        return $this->translating(function () use ($pageable): Page {
            $total = $this->query()->count();

            $items = $this->applySort($this->query(), $pageable->sort)
                ->skip($pageable->offset())
                ->take($pageable->size)
                ->get()
                ->all();

            return new Page($this->narrow($items), $total, $pageable->page, $pageable->size);
        });
    }

    /**
     * @return list<TModel>
     */
    public function findSorted(Sort $sort): array
    {
        return $this->translating(fn (): array => $this->narrow($this->applySort($this->query(), $sort)->get()->all()));
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
        return $this->translating(fn (): array => $this->narrow($specification->toBuilder($this->query())->get()->all()));
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
        return $this->translating(function () use ($specification, $pageable): Page {
            $total = $specification->toBuilder($this->query())->count();

            $items = $this->applySort($specification->toBuilder($this->query()), $pageable->sort)
                ->skip($pageable->offset())
                ->take($pageable->size)
                ->get()
                ->all();

            return new Page($this->narrow($items), $total, $pageable->page, $pageable->size);
        });
    }

    /**
     * Query by example: the probe's set attributes under the matcher's rules. An Example IS a Specification, so
     * this is findBySpecification() with a name Spring Data users expect.
     *
     * @return list<TModel>
     */
    public function findByExample(Example $example): array
    {
        return $this->findBySpecification($example);
    }

    /**
     * At most one row: null for none, the row for one, IncorrectResultSizeDataAccessException for more (the
     * probe was not selective enough — a programming error, like Spring's findOne(Example)). Two rows are fetched
     * so "more than one" is known without a count query.
     *
     * @return TModel|null
     */
    public function findOneByExample(Example $example): ?object
    {
        return $this->translating(function () use ($example): ?object {
            $rows = $this->narrow($example->toBuilder($this->query())->limit(2)->get()->all());

            if (count($rows) > 1) {
                throw new IncorrectResultSizeDataAccessException(
                    sprintf('findOneByExample() on %s matched more than one row.', class_basename($this->model)),
                );
            }

            return $rows[0] ?? null;
        });
    }

    public function countByExample(Example $example): int
    {
        return $this->translating(fn (): int => $example->toBuilder($this->query())->count());
    }

    public function existsByExample(Example $example): bool
    {
        return $this->translating(fn (): bool => $example->toBuilder($this->query())->exists());
    }

    /**
     * @return Page<TModel>
     */
    public function findByExamplePaged(Example $example, Pageable $pageable): Page
    {
        return $this->findBySpecificationPaged($example, $pageable);
    }

    /**
     * Reads including soft-deleted rows (removes the SoftDeletingScope). Meaningful only for models that use the
     * native SoftDeletes trait; on a non-soft-deletable model the scope is simply absent (a harmless no-op).
     * Narrowed via `narrow()` like every other list-returning method on this class (not a bare `->all()`).
     *
     * @return list<TModel>
     */
    public function findAllIncludingDeleted(): array
    {
        return $this->translating(fn (): array => $this->narrow($this->query()->withoutGlobalScope(SoftDeletingScope::class)->get()->all()));
    }

    /**
     * Restores a soft-deleted row by nulling the conventional `deleted_at` column, then re-reads it (now visible
     * to the default scope). Returns null if no such row exists.
     *
     * @return TModel|null
     */
    public function restore(mixed $id): ?object
    {
        return $this->translating(function () use ($id): ?object {
            $this->query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where($this->keyName(), '=', $id)
                ->update(['deleted_at' => null]);

            return $this->findById($id);
        });
    }

    /**
     * The translation guard: whatever escapes $work leaves as a member of the DataAccessException family (or
     * unchanged, when it is not a database failure). Idempotent — a translated exception passes through — so a
     * public method that calls another public method (deleteById -> findById) translates exactly once.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    protected function translating(Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            throw $this->translator->translate($e, $this->driverName());
        }
    }

    /**
     * The model connection's driver, for the translator's per-driver code table. A connection that cannot even
     * be opened has no driver to report; the translator then resolves by SQLSTATE and Laravel's typed exceptions.
     */
    protected function driverName(): ?string
    {
        try {
            return (new $this->model)->getConnection()->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The declared repository class the manifest knows — static::class with the generated proxy suffix removed.
     *
     * @return class-string
     */
    protected function repositoryClass(): string
    {
        $class = static::class;
        if (str_ends_with($class, self::PROXY_SUFFIX)) {
            /** @var class-string $declared */
            $declared = substr($class, 0, -strlen(self::PROXY_SUFFIX));

            return $declared;
        }

        return $class;
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

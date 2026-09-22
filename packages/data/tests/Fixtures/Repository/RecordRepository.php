<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\Attributes\EntityGraph;
use Firefly\Data\Repository\Attributes\Lock;
use Firefly\Data\Repository\Attributes\Modifying;
use Firefly\Data\Repository\Attributes\Projection;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Locking\LockMode;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Slice;

/**
 * A concrete #[Repository] over Record with derived-query methods (resolved by __call, typed for PHPStan via the
 * method-tag hints below), one explicit #[Query] method, and one DECLARED method per repository attribute the
 * scanner records — every declared body is the one-line dispatchQuery() delegation, so what differs between
 * them is only the attribute.
 *
 * @extends EloquentRepository<Record>
 *
 * @method list<Record> findByStatusAndAmountGreaterThan(string $status, int $amount)
 * @method list<Record> findTop2ByStatusOrderByAmountDesc(string $status)
 * @method bool existsByEmailIgnoreCase(string $email)
 * @method int countByStatus(string $status)
 * @method int deleteByStatus(string $status)
 * @method Page<Record> findByStatus(string $status, Pageable $pageable)
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;

    /** @var array<string, list<string>> */
    protected array $entityGraphs = ['Record.full' => ['entries']];

    /**
     * An explicit-SQL override, discovered by TransactionalScanner into the manifest; the body delegates to the
     * shared dispatcher (which finds it in the #[Query] set and runs the SQL with positional binding).
     *
     * @return list<array<string, mixed>>
     */
    #[Query('select * from records where email = :email order by amount asc')]
    public function findByEmailRaw(string $email): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /** A statement that needs a transaction: returns the affected-row count. */
    #[Modifying]
    #[Query('update records set status = :status where amount < :amount')]
    public function closeSmall(string $status, int $amount): int
    {
        $affected = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_int($affected));

        return $affected;
    }

    /** A statement that may run without a transaction. */
    #[Modifying(requiresTransaction: false)]
    #[Query('delete from records where status = :status')]
    public function purgeStatus(string $status): int
    {
        $affected = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_int($affected));

        return $affected;
    }

    /**
     * A derived query hydrated into a DTO; the SELECT list is inferred from RecordSummary's constructor.
     *
     * @return list<RecordSummary>
     */
    #[Projection(RecordSummary::class)]
    public function findByStatusOrderByAmountAsc(string $status): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<RecordSummary> $rows */
        return $rows;
    }

    /**
     * Explicit SQL hydrated into the same DTO; the SQL owns the select list.
     *
     * @return list<RecordSummary>
     */
    #[Projection(RecordSummary::class)]
    #[Query('select id, email, amount from records where status = :status order by amount asc')]
    public function summariesByStatusRaw(string $status): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<RecordSummary> $rows */
        return $rows;
    }

    /**
     * A projection onto a DTO that wants a column the table does not have.
     *
     * @return list<RecordNickname>
     */
    #[Projection(RecordNickname::class)]
    public function findByAmountLessThan(int $amount): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<RecordNickname> $rows */
        return $rows;
    }

    /** @return list<Record> */
    #[Lock(LockMode::PESSIMISTIC_WRITE)]
    public function findByEmail(string $email): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<Record> $rows */
        return $rows;
    }

    /** @return list<Record> */
    #[Lock(LockMode::PESSIMISTIC_READ)]
    public function findByAmountBetween(int $low, int $high): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<Record> $rows */
        return $rows;
    }

    /** @return list<Record> */
    #[EntityGraph(attributePaths: ['entries'])]
    public function findByStatusOrderByIdDesc(string $status): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<Record> $rows */
        return $rows;
    }

    /**
     * The inherited read under a NAMED graph — the Spring shape of annotating an overridden findAll().
     *
     * @return list<Record>
     */
    #[EntityGraph('Record.full')]
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * A derived query that pages as a Slice: the trailing Pageable is what makes it page, the declared return
     * type is what makes it a Slice rather than a Page.
     *
     * @return Slice<Record>
     */
    #[EntityGraph(attributePaths: ['entries'])]
    public function findByStatusOrderByIdAsc(string $status, Pageable $pageable): Slice
    {
        $slice = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert($slice instanceof Slice);

        /** @var Slice<Record> $slice */
        return $slice;
    }
}

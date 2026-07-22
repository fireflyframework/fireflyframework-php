<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;

/**
 * A concrete #[Repository] over Record with derived-query methods (resolved by __call, typed for PHPStan via the
 * method-tag hints below) plus one explicit #[Query] method that delegates to the shared dispatcher.
 *
 * @extends EloquentRepository<Record>
 *
 * @method list<Record> findByStatusAndAmountGreaterThan(string $status, int $amount)
 * @method list<Record> findTop2ByStatusOrderByAmountDesc(string $status)
 * @method bool existsByEmailIgnoreCase(string $email)
 * @method int countByStatus(string $status)
 * @method int deleteByStatus(string $status)
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;

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
}

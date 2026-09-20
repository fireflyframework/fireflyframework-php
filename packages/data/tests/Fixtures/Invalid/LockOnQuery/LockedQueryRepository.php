<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Invalid\LockOnQuery;

use Firefly\Data\Repository\Attributes\Lock;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Tests\Fixtures\Repository\Record;

/**
 * INVALID BY DESIGN: #[Lock] on a #[Query]. Scanned only by the test that expects the refusal.
 *
 * @extends EloquentRepository<Record>
 */
final class LockedQueryRepository extends EloquentRepository
{
    protected string $model = Record::class;

    /** @return list<array<string, mixed>> */
    #[Lock]
    #[Query('select * from records where id = :id')]
    public function lockedRaw(int $id): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Invalid\ModifyingWithoutQuery;

use Firefly\Data\Repository\Attributes\Modifying;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Tests\Fixtures\Repository\Record;

/**
 * INVALID BY DESIGN: #[Modifying] on a derived method. Scanned only by the test that expects the refusal.
 *
 * @extends EloquentRepository<Record>
 */
final class BareModifyingRepository extends EloquentRepository
{
    protected string $model = Record::class;

    #[Modifying]
    public function deleteByStatus(string $status): int
    {
        $n = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_int($n));

        return $n;
    }
}

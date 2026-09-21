<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Invalid\ModifyingSelect;

use Firefly\Data\Repository\Attributes\Modifying;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Tests\Fixtures\Repository\Record;

/**
 * INVALID BY DESIGN: #[Modifying] over a SELECT. Scanned only by the test that expects the refusal.
 *
 * @extends EloquentRepository<Record>
 */
final class SelectingRepository extends EloquentRepository
{
    protected string $model = Record::class;

    #[Modifying]
    #[Query('  /* count them */ select count(*) from records')]
    public function countAll(): int
    {
        $n = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_int($n));

        return $n;
    }
}

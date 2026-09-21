<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Attributes;

use Attribute;
use Firefly\Data\Repository\Locking\LockMode;

/**
 * A pessimistic row lock on a derived-query method — Spring Data's @Lock(LockModeType.PESSIMISTIC_WRITE). The
 * builder gets lockForUpdate() or sharedLock(); the method refuses to run outside an active transaction on the
 * model's connection (TransactionRequiredException), because a lock released at the end of the statement is
 * no lock at all. Refused on a #[Query] method at scan time: a raw statement carries its own locking clause.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Lock
{
    public function __construct(
        public LockMode $mode = LockMode::PESSIMISTIC_WRITE,
    ) {}
}

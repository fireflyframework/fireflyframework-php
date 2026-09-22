<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Locking;

/**
 * The two pessimistic modes a relational row lock has: WRITE is `FOR UPDATE` (lockForUpdate()), READ is
 * `FOR SHARE` / `LOCK IN SHARE MODE` (sharedLock()). Optimistic locking is a model trait (HasOptimisticLock),
 * not a mode. String-backed so the manifest serialises the name verbatim.
 */
enum LockMode: string
{
    case PESSIMISTIC_READ = 'PESSIMISTIC_READ';
    case PESSIMISTIC_WRITE = 'PESSIMISTIC_WRITE';
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Locking;

use Firefly\Kernel\Exception\Infrastructure\DataAccessException;
use Throwable;

/**
 * A concurrent-write conflict: the row's version moved between load and save. Extends the frozen kernel
 * DataAccessException (Data -> Kernel is an allowed edge) with an OPTIMISTIC_LOCK error code.
 */
final class OptimisticLockException extends DataAccessException
{
    public function __construct(string $entity, int|string|null $id, int $expectedVersion, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Optimistic lock failure updating [%s#%s]: expected version %d but the row changed underneath.', $entity, (string) $id, $expectedVersion),
            'OPTIMISTIC_LOCK',
            $previous,
        );
    }
}

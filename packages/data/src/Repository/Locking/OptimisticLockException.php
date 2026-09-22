<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Locking;

use Firefly\Kernel\Exception\Infrastructure\OptimisticLockingFailureException;
use Throwable;

/**
 * A concurrent-write conflict: the row's version moved between load and save. The concrete member of the
 * kernel's OptimisticLockingFailureException (Data -> Kernel is an allowed edge), so a `catch` on either name
 * works; it keeps its own OPTIMISTIC_LOCK error code and the message that names the entity and the version.
 */
final class OptimisticLockException extends OptimisticLockingFailureException
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

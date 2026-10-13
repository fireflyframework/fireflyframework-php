<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

use Firefly\Kernel\Exception\Infrastructure\OptimisticLockingFailureException;
use Throwable;

/**
 * Another writer appended to the stream first: the expected version is not the stream's version any more. A 409,
 * the kernel's OptimisticLockingFailureException, so a `catch` on either name works. Reload the aggregate and run
 * the command again. On the database store this is what UNIQUE(aggregate_id, aggregate_version) turns a racing
 * append into, through firefly/data's PersistenceExceptionTranslator; the duplicate key stays `previous`.
 */
final class ConcurrencyException extends OptimisticLockingFailureException
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('The stream of aggregate [%s] is at version %d, not the expected %d: another writer appended first.', $aggregateId, $actualVersion, $expectedVersion),
            'EVENT_STORE_CONCURRENCY',
            $previous,
        );
    }

    public static function conflict(string $aggregateId, int $expected, int $actual, ?Throwable $previous = null): self
    {
        return new self($aggregateId, $expected, $actual, $previous);
    }
}

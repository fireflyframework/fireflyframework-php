<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Closure;
use DateTimeImmutable;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\EventSourcing\Exception\CheckpointConflictException;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Illuminate\Database\Connection;
use PDOException;
use Throwable;

/**
 * firefly.eventsourcing.projection.checkpoint-store=database: firefly_eventsourcing_checkpoints on the store's
 * connection, one row per projection.
 *
 * advance() is a conditional UPDATE by exact primary key (`WHERE projection = ? AND position = ?`), so of two
 * runners that read the same position exactly one moves it: PostgreSQL, MySQL, MariaDB and SQL Server re-evaluate
 * the condition once the first runner commits, and SQLite has one writer at a time. A projection's FIRST advance
 * inserts its row instead. The missing row is found with a plain, non-locking read, never with an UPDATE that
 * matches nothing: under InnoDB's REPEATABLE READ that UPDATE takes a gap lock, and two runners creating rows
 * would then deadlock on each other's INSERT. The insert runs in a SAVEPOINT, so a duplicate key (a rival created
 * the row first) leaves the caller's transaction usable on PostgreSQL and becomes `false`, the same answer as a
 * lost conditional UPDATE. There is no MERGE and no range scan, so SQL Server never waits on another runner's rows.
 *
 * MariaDB's snapshot isolation (innodb_snapshot_isolation, ON by default since 11.8) answers the same race with
 * ER_CHECKREAD (1020, "Record has changed since last read") on the UPDATE or the INSERT, and that error ABORTS the
 * caller's transaction: there is nothing left to return `false` into, so advance() throws
 * CheckpointConflictException, the fenced answer the ProjectionRunner already maps to FENCED.
 */
final class DatabaseCheckpointStore implements CheckpointStore
{
    private readonly PersistenceExceptionTranslator $translator;

    public function __construct(private readonly Connection $connection)
    {
        $this->translator = new PersistenceExceptionTranslator(true);
    }

    public function get(string $projection): Checkpoint
    {
        $row = $this->connection->table(EventSourcingSchema::CHECKPOINTS)->where('projection', $projection)->first();

        return $row === null ? new Checkpoint($projection) : self::hydrate((array) $row);
    }

    public function all(): array
    {
        $all = [];
        foreach ($this->connection->table(EventSourcingSchema::CHECKPOINTS)->orderBy('projection')->get() as $row) {
            $checkpoint = self::hydrate((array) $row);
            $all[$checkpoint->projection] = $checkpoint;
        }
        ksort($all, SORT_STRING);

        return $all;
    }

    public function advance(string $projection, int $from, int $to): bool
    {
        EventSourcingSchema::fit('projection', $projection, EventSourcingSchema::NAME_LENGTH);
        Checkpoint::forward($projection, $from, $to);
        $moved = ['position' => $to, 'updated_at' => EventSourcingSchema::instant(new DateTimeImmutable), 'last_error' => null, 'failed_at' => null];

        try {
            if ($from === 0 && ! $this->exists($projection)) {
                return $this->insert(['projection' => $projection] + $moved);
            }

            return $this->connection->table(EventSourcingSchema::CHECKPOINTS)
                ->where('projection', $projection)
                ->where('position', $from)
                ->update($moved) === 1;
        } catch (Throwable $failure) {
            if ($this->changedSinceRead($failure)) {
                throw CheckpointConflictException::of($projection, $from, $failure);
            }

            throw $failure;
        }
    }

    public function reset(string $projection, int $position = 0): void
    {
        EventSourcingSchema::fit('projection', $projection, EventSourcingSchema::NAME_LENGTH);
        $row = ['position' => max(0, $position), 'updated_at' => EventSourcingSchema::instant(new DateTimeImmutable), 'last_error' => null, 'failed_at' => null];

        if ($this->exists($projection) || ! $this->insert(['projection' => $projection] + $row)) {
            $this->connection->table(EventSourcingSchema::CHECKPOINTS)->where('projection', $projection)->update($row);
        }
    }

    public function recordFailure(string $projection, string $error, DateTimeImmutable $at): void
    {
        EventSourcingSchema::fit('projection', $projection, EventSourcingSchema::NAME_LENGTH);
        $instant = EventSourcingSchema::instant($at);
        $failure = ['last_error' => mb_substr($error, 0, 4000, 'UTF-8'), 'failed_at' => $instant, 'updated_at' => $instant];

        if ($this->exists($projection) || ! $this->insert(['projection' => $projection, 'position' => 0] + $failure)) {
            $this->connection->table(EventSourcingSchema::CHECKPOINTS)->where('projection', $projection)->update($failure);
        }
    }

    public function transactional(Closure $work): mixed
    {
        return $this->connection->transaction(static fn () => $work());
    }

    public function isHealthy(): bool
    {
        try {
            $this->connection->table(EventSourcingSchema::CHECKPOINTS)->limit(1)->get(['projection']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function exists(string $projection): bool
    {
        return $this->connection->table(EventSourcingSchema::CHECKPOINTS)->where('projection', $projection)->exists();
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row): bool
    {
        try {
            $this->connection->transaction(fn () => $this->connection->table(EventSourcingSchema::CHECKPOINTS)->insert($row));

            return true;
        } catch (Throwable $failure) {
            if ($this->changedSinceRead($failure)) {
                throw $failure;
            }
            $translated = $this->translator->translate($failure, (string) $this->connection->getDriverName());
            if ($translated instanceof DuplicateKeyException) {
                return false;
            }

            throw $translated;
        }
    }

    /**
     * MariaDB's ER_CHECKREAD, raised as a QueryException or wrapped in the DeadlockException Laravel throws from a
     * nested transaction: a rival committed the row after this transaction's snapshot, and the transaction is gone.
     */
    private function changedSinceRead(Throwable $failure): bool
    {
        if (! in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && ($cause->errorInfo[1] ?? null) === 1020) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $row */
    private static function hydrate(array $row): Checkpoint
    {
        $projection = $row['projection'] ?? '';
        $position = $row['position'] ?? 0;
        $updatedAt = $row['updated_at'] ?? null;
        $lastError = $row['last_error'] ?? null;
        $failedAt = $row['failed_at'] ?? null;

        return new Checkpoint(
            is_scalar($projection) ? (string) $projection : '',
            is_numeric($position) ? (int) $position : 0,
            is_string($updatedAt) ? EventSourcingSchema::parseInstant($updatedAt) : null,
            is_string($lastError) ? $lastError : null,
            is_string($failedAt) ? EventSourcingSchema::parseInstant($failedAt) : null,
        );
    }
}

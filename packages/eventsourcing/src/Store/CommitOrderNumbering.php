<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use DateTimeImmutable;
use Firefly\Data\Capability\DatabaseCapabilities;
use Illuminate\Database\Connection;

/**
 * Gives committed events their global positions, in commit order: PyFly's `head-row` strategy over an insert-order
 * surrogate key.
 *
 * THE CAVEAT IT SOLVES. An identity or sequence value is taken when a row is INSERTed, not when its transaction
 * commits, so a transaction holding position 10 can commit after the one holding 11. A projection that read 11 and
 * checkpointed it would never see 10. That happens on every server LaraFly speaks.
 *
 * THE SOLUTION. An event is inserted with no position. A reader numbers before it reads, in a short transaction
 * of its own:
 *
 *  1. Take the head row's write lock by UPDATEing it. On SQLite this is the database write lock.
 *  2. Read its position.
 *  3. Take up to $batchSize COMMITTED unnumbered rows by `id`, and give each position + 1… by exact primary key.
 *  4. Move the head.
 *
 * Only committed rows are ever numbered:
 *
 *  - PostgreSQL sees committed rows only.
 *  - MySQL/MariaDB run this transaction at READ COMMITTED (sent unprepared, before BEGIN).
 *  - SQL Server reads with READPAST, so it neither waits on nor sees an uncommitted append.
 *
 * Numberers serialize on the head row, so a position is only ever given above every position given before, and a
 * reader never sees one appear below a position it has already read. An aggregate's events keep version order: its
 * version v+1 can only be inserted after v committed, so its `id` is larger.
 *
 * number() does nothing inside an open transaction. The head lock is never held for a caller's unit of work, and a
 * read inside a transaction returns the events that are already numbered.
 */
final class CommitOrderNumbering
{
    private readonly bool $readCommitted;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $batchSize = 500,
    ) {
        $this->readCommitted = DatabaseCapabilities::fromVersion((string) $connection->getDriverName(), '')->isMySqlFamily();
    }

    /** Numbers every committed, unnumbered event; returns how many it numbered. */
    public function number(): int
    {
        if ($this->connection->transactionLevel() > 0 || ! $this->pending()) {
            return 0;
        }

        $total = 0;
        do {
            $numbered = $this->round();
            $total += $numbered;
        } while ($numbered === $this->batchSize);

        return $total;
    }

    public function pending(): bool
    {
        return EventSourcingSchema::readPast($this->connection->table(EventSourcingSchema::EVENTS)->whereNull('global_position'), $this->connection)->exists();
    }

    private function round(): int
    {
        if ($this->readCommitted) {
            $this->connection->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $this->connection->transaction(function (): int {
            $now = EventSourcingSchema::instant(new DateTimeImmutable);
            $this->connection->table(EventSourcingSchema::HEAD)->where('name', EventSourcingSchema::GLOBAL_STREAM)->update(['updated_at' => $now]);

            $head = $this->connection->table(EventSourcingSchema::HEAD)->where('name', EventSourcingSchema::GLOBAL_STREAM)->value('position');
            if ($head === null) {
                $this->connection->table(EventSourcingSchema::HEAD)->insert(['name' => EventSourcingSchema::GLOBAL_STREAM, 'position' => 0, 'updated_at' => $now]);
                $head = 0;
            }
            $position = is_numeric($head) ? (int) $head : 0;

            $ids = EventSourcingSchema::readPast(
                $this->connection->table(EventSourcingSchema::EVENTS)->whereNull('global_position')->orderBy('id')->limit($this->batchSize),
                $this->connection,
            )->pluck('id')->all();

            foreach ($ids as $id) {
                $this->connection->table(EventSourcingSchema::EVENTS)->where('id', $id)->update(['global_position' => ++$position]);
            }
            if ($ids !== []) {
                $this->connection->table(EventSourcingSchema::HEAD)->where('name', EventSourcingSchema::GLOBAL_STREAM)->update(['position' => $position]);
            }

            return count($ids);
        });
    }
}

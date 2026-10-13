<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Closure;
use DateTimeImmutable;

/**
 * Where projections keep their position. Selected by firefly.eventsourcing.projection.checkpoint-store (`memory` |
 * `database`, null follows store.provider). Every adapter passes CheckpointStoreContract.
 *
 * advance() is the FENCE: it moves a checkpoint only from the position the caller read, so when a lease expired
 * mid-batch and a second runner moved first, the late runner's advance() is false and its batch rolls back.
 */
interface CheckpointStore
{
    public function get(string $projection): Checkpoint;

    /** @return array<string, Checkpoint> by projection name */
    public function all(): array;

    /** Moves `$projection` from `$from` to `$to` and clears its failure; false when its position is no longer `$from`. */
    public function advance(string $projection, int $from, int $to): bool;

    public function reset(string $projection, int $position = 0): void;

    /** Records why the newest batch rolled back, keeping the position. */
    public function recordFailure(string $projection, string $error, DateTimeImmutable $at): void;

    /**
     * Runs `$work` in one unit of work with the checkpoint: a throw undoes every advance inside it (and, for the
     * database store, every row the work wrote on the same connection).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function transactional(Closure $work): mixed;

    public function isHealthy(): bool;
}

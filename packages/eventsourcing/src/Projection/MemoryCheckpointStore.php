<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Closure;
use DateTimeImmutable;
use Throwable;

/** firefly.eventsourcing.projection.checkpoint-store=memory: one process; transactional() restores the map on a throw. */
final class MemoryCheckpointStore implements CheckpointStore
{
    /** @var array<string, Checkpoint> */
    private array $checkpoints = [];

    public function get(string $projection): Checkpoint
    {
        return $this->checkpoints[$projection] ?? new Checkpoint($projection);
    }

    public function all(): array
    {
        $all = $this->checkpoints;
        ksort($all, SORT_STRING);

        return $all;
    }

    public function advance(string $projection, int $from, int $to): bool
    {
        Checkpoint::forward($projection, $from, $to);
        if ($this->get($projection)->position !== $from) {
            return false;
        }

        $this->checkpoints[$projection] = new Checkpoint($projection, $to, new DateTimeImmutable);

        return true;
    }

    public function reset(string $projection, int $position = 0): void
    {
        $this->checkpoints[$projection] = new Checkpoint($projection, max(0, $position), new DateTimeImmutable);
    }

    public function recordFailure(string $projection, string $error, DateTimeImmutable $at): void
    {
        $this->checkpoints[$projection] = new Checkpoint($projection, $this->get($projection)->position, $at, $error, $at);
    }

    public function transactional(Closure $work): mixed
    {
        $before = $this->checkpoints;
        try {
            return $work();
        } catch (Throwable $failure) {
            $this->checkpoints = $before;

            throw $failure;
        }
    }

    public function isHealthy(): bool
    {
        return true;
    }
}

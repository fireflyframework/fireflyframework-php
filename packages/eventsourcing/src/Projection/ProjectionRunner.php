<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\EventSourcing\Exception\CheckpointConflictException;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Advances projections along the global stream (jafly's ProjectionService, PyFly's ProjectionRunner).
 *
 * ONE BATCH, until the head:
 *
 *  1. Take the projection's lease on the DistributedLock port (`firefly.eventsourcing.projection.<name>`, for
 *     firefly.eventsourcing.projection.lease-ttl). Not acquired: another node is advancing it, `leased-elsewhere`.
 *  2. Read the checkpoint, then up to `batch-size` events after it from EventStore::readAll(). That read happens
 *     OUTSIDE any transaction, so the store numbers the events that committed (the commit-order guarantee: nothing
 *     can later appear below a position already read).
 *  3. In CheckpointStore::transactional(): hand the projection every event whose type it handles(), then advance the
 *     checkpoint from the position read in step 2 to the batch's last position. The advance is FENCED: when a
 *     runner whose lease outlived ours moved first, it is false, the batch rolls back, `fenced`. A projection that
 *     throws rolls the batch back too; its failure is recorded on the checkpoint, `failed`, and the next cycle
 *     retries it.
 *  4. Release the lease. The port has no extend, so the lease is taken per batch and lease-ttl must exceed one.
 *
 * catchUpAll() and live mode skip InlineProjections: their writer keeps them current, and rebuild() is the one path
 * that replays them. rebuild() resets the projection and its checkpoint in one transaction under the lease, then
 * catches up from position 0.
 */
final class ProjectionRunner
{
    public const string LOCK_PREFIX = 'firefly.eventsourcing.projection.';

    private bool $stopping = false;

    /**
     * @param  (Closure(float): void)|null  $sleep  how live mode waits (seconds); usleep() when null
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly EventStore $store,
        private readonly CheckpointStore $checkpoints,
        private readonly ProjectionRegistry $projections,
        private readonly DistributedLock $lock = new NoneLock,
        private readonly int $batchSize = 100,
        private readonly float $leaseTtl = 30.0,
        private readonly float $pollInterval = 1.0,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly ?Closure $sleep = null,
        private readonly ?Closure $clock = null,
    ) {}

    public function projections(): ProjectionRegistry
    {
        return $this->projections;
    }

    public function catchUp(string $name): ProjectionRunResult
    {
        $projection = $this->projections->get($name);
        if ($projection instanceof InlineProjection) {
            throw new EventSourcingException(
                "The projection [{$name}] is inline: its writer keeps it current. Rebuild it with php artisan firefly:eventsourcing:projections:rebuild {$name}.",
                'EVENT_SOURCING_INLINE_PROJECTION',
                409,
            );
        }

        return $this->drive($projection);
    }

    /** @return list<ProjectionRunResult> one per catch-up projection, by name */
    public function catchUpAll(): array
    {
        $results = [];
        foreach ($this->projections->all() as $projection) {
            if (! $projection instanceof InlineProjection) {
                $results[] = $this->drive($projection);
            }
        }

        return $results;
    }

    /**
     * Catches every projection up, again and again, sleeping poll-interval after a cycle that found nothing, until
     * $maxRuntime seconds passed, stop() was called (the command traps SIGINT/SIGTERM into it) or $shouldStop says so.
     *
     * @param  (Closure(): bool)|null  $shouldStop  asked before every cycle
     * @return int the cycles run
     */
    public function runLive(?float $maxRuntime = null, ?Closure $shouldStop = null): int
    {
        $this->stopping = false;
        $deadline = $maxRuntime === null ? null : microtime(true) + max(0.0, $maxRuntime);
        $cycles = 0;

        while (! $this->stopping && ($deadline === null || microtime(true) < $deadline)) {
            if ($shouldStop !== null && $shouldStop() === true) {
                break;
            }

            $handled = 0;
            foreach ($this->catchUpAll() as $result) {
                $handled += $result->handled;
            }
            $cycles++;

            if ($handled === 0 && ! $this->stopping) {
                $this->pause($deadline);
            }
        }

        return $cycles;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function rebuild(string $name): ProjectionRunResult
    {
        $projection = $this->projections->get($name);
        $lease = self::LOCK_PREFIX.$name;
        if (! $this->lock->tryAcquire($lease, $this->leaseTtl)) {
            return new ProjectionRunResult($name, ProjectionRunResult::LEASED_ELSEWHERE, 0, 0);
        }

        try {
            $this->checkpoints->transactional(function () use ($projection, $name): void {
                $projection->reset();
                $this->checkpoints->reset($name, 0);
            });
        } finally {
            $this->lock->release($lease);
        }

        return $this->drive($projection);
    }

    public function lag(string $name): int
    {
        $projection = $this->projections->get($name);

        return $projection instanceof InlineProjection ? 0 : max(0, $this->store->currentPosition() - $this->checkpoints->get($name)->position);
    }

    /** @return list<ProjectionStatus> by name */
    public function statuses(): array
    {
        $head = $this->store->currentPosition();
        $checkpoints = $this->checkpoints->all();
        $statuses = [];
        foreach ($this->projections->all() as $projection) {
            $name = $projection->name();
            $checkpoint = $checkpoints[$name] ?? new Checkpoint($name);
            $inline = $projection instanceof InlineProjection;
            $statuses[] = new ProjectionStatus(
                $name,
                $inline,
                $checkpoint->position,
                $head,
                $inline ? 0 : max(0, $head - $checkpoint->position),
                $checkpoint->lastError,
                $checkpoint->failedAt,
                $checkpoint->updatedAt,
            );
        }

        return $statuses;
    }

    private function drive(Projection $projection): ProjectionRunResult
    {
        $name = $projection->name();
        $lease = self::LOCK_PREFIX.$name;
        $start = $this->checkpoints->get($name)->position;
        $position = $start;
        $handled = 0;

        while (true) {
            if (! $this->lock->tryAcquire($lease, $this->leaseTtl)) {
                return new ProjectionRunResult($name, ProjectionRunResult::LEASED_ELSEWHERE, $start, $position, $handled);
            }

            try {
                $from = $this->checkpoints->get($name)->position;
                $events = $this->store->readAll($from, $this->batchSize);
                if ($events === []) {
                    return new ProjectionRunResult($name, ProjectionRunResult::CAUGHT_UP, $start, $from, $handled);
                }
                $to = $events[count($events) - 1]->globalPosition ?? $from;

                try {
                    $handled += $this->checkpoints->transactional(function () use ($projection, $events, $name, $from, $to): int {
                        $types = $projection->handles();
                        $count = 0;
                        foreach ($events as $event) {
                            if ($types === [] || in_array($event->eventType, $types, true)) {
                                $projection->handle($event);
                                $count++;
                            }
                        }
                        if (! $this->checkpoints->advance($name, $from, $to)) {
                            throw CheckpointConflictException::of($name, $from);
                        }

                        return $count;
                    });
                } catch (CheckpointConflictException) {
                    return new ProjectionRunResult($name, ProjectionRunResult::FENCED, $start, $position, $handled);
                } catch (Throwable $failure) {
                    $error = $failure::class.': '.$failure->getMessage();
                    $this->checkpoints->recordFailure($name, $error, $this->now());
                    $this->logger->error(
                        sprintf('Projection [%s] failed after position %d: %s', $name, $from, $failure->getMessage()),
                        ['projection' => $name, 'position' => $from, 'exception' => $failure],
                    );

                    return new ProjectionRunResult($name, ProjectionRunResult::FAILED, $start, $position, $handled, $error);
                }

                $position = $to;
                if (count($events) < $this->batchSize) {
                    return new ProjectionRunResult($name, ProjectionRunResult::CAUGHT_UP, $start, $position, $handled);
                }
            } finally {
                $this->lock->release($lease);
            }
        }
    }

    private function pause(?float $deadline): void
    {
        $seconds = $deadline === null ? $this->pollInterval : min($this->pollInterval, max(0.0, $deadline - microtime(true)));
        if ($seconds <= 0.0) {
            return;
        }

        if ($this->sleep !== null) {
            ($this->sleep)($seconds);

            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock === null ? new DateTimeImmutable : ($this->clock)())->setTimezone(new DateTimeZone('UTC'));
    }
}

<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Symfony\Component\Process\Process;

/** Waits for a racer's "ready" line by reading its ACCUMULATED output (see OutboxConcurrencyIntegrationTest for why not waitUntil()). */
function appendRaceAwaitReady(Process $process, float $timeoutSeconds = 60.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $running = $process->isRunning();
        if (str_contains($process->getOutput(), 'ready')) {
            return true;
        }
        if (! $running) {
            return false;
        }
        usleep(1000);
    }

    return false;
}

/**
 * Starts one racer per aggregate id, releases them together and returns each racer's last output line.
 *
 * @param  list<string>  $aggregateIds
 * @return list<string>
 */
function appendRace(string $lane, string $mode, array $aggregateIds): array
{
    $go = sys_get_temp_dir().'/firefly-es-race-'.bin2hex(random_bytes(6));
    $script = dirname(__DIR__).'/Support/append-racer.php';
    $processes = [];
    foreach ($aggregateIds as $id) {
        $arguments = [PHP_BINARY, $script, $lane, $mode, $go, $id];
        if ($lane === 'sqlite') {
            $arguments[] = EventSourcingDatabase::sqlitePath();
        }
        $process = new Process($arguments, timeout: 120);
        $process->start();
        $processes[] = $process;
    }

    try {
        foreach ($processes as $process) {
            expect(appendRaceAwaitReady($process))->toBeTrue('a racer never became ready: '.$process->getErrorOutput());
        }
        touch($go);
        foreach ($processes as $process) {
            $process->wait();
        }
    } finally {
        if (is_file($go)) {
            unlink($go);
        }
    }

    return array_map(static function (Process $process): string {
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return $lines[count($lines) - 1] ?? '';
    }, $processes);
}

foreach (EventSourcingDatabase::lanes() as $lane) {
    it("lets exactly one of four racing appenders on one aggregate win on {$lane}", function () use ($lane): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        try {
            $outcomes = appendRace($lane, 'one', ['contested', 'contested', 'contested', 'contested']);
            sort($outcomes);

            expect($outcomes)->toBe(['conflict', 'conflict', 'conflict', 'won'])
                ->and((new DatabaseEventStore($connection))->version('contested'))->toBe(3)
                ->and(count(array_unique(array_map(static fn (StoredEvent $e): int => is_int($e->payload['by'] ?? null) ? $e->payload['by'] : -1, (new DatabaseEventStore($connection))->load('contested')->events))))->toBe(1);
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));

    it("lets racing appenders on different aggregates all win, numbered without a hole and in version order on {$lane}", function () use ($lane): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        try {
            expect(appendRace($lane, 'many', ['r-1', 'r-2', 'r-3', 'r-4']))->toBe(['appended 25', 'appended 25', 'appended 25', 'appended 25']);

            $all = (new DatabaseEventStore($connection))->readAll(0, 1000);
            $positions = array_map(static fn (StoredEvent $e): ?int => $e->globalPosition, $all);
            $versionsByAggregate = [];
            foreach ($all as $event) {
                $versionsByAggregate[$event->aggregateId][] = $event->aggregateVersion;
            }

            expect($positions)->toBe(range(1, 100))
                ->and(array_keys($versionsByAggregate))->toHaveCount(4);
            foreach ($versionsByAggregate as $versions) {
                expect($versions)->toBe(range(1, 25));
            }
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
}

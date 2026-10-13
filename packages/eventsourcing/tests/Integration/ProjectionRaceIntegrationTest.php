<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\Process\Process;

/** Waits for a racer's "ready" line in its ACCUMULATED output (the AppendRaceIntegrationTest helper's twin). */
function projectionRaceAwaitReady(Process $process, float $timeoutSeconds = 60.0): bool
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
 * Starts $runners projection racers, releases them together and returns each racer's last output line.
 *
 * @return list<string>
 */
function projectionRace(string $lane, string $table, int $runners): array
{
    $go = sys_get_temp_dir().'/firefly-es-projection-race-'.bin2hex(random_bytes(6));
    $script = dirname(__DIR__).'/Support/projection-racer.php';
    $processes = [];
    for ($n = 0; $n < $runners; $n++) {
        $arguments = [PHP_BINARY, $script, $lane, $go, $table];
        if ($lane === 'sqlite') {
            $arguments[] = EventSourcingDatabase::sqlitePath();
        }
        $process = new Process($arguments, timeout: 180);
        $process->start();
        $processes[] = $process;
    }

    try {
        foreach ($processes as $process) {
            expect(projectionRaceAwaitReady($process))->toBeTrue('a racer never became ready: '.$process->getErrorOutput());
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
    it("projects every event exactly once while two unleased runners race on {$lane}", function () use ($lane): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        $table = 'firefly_es_race_tally';
        try {
            $schema = $connection->getSchemaBuilder();
            $schema->dropIfExists($table);
            $schema->create($table, static function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->bigInteger('global_position');
                $t->string('runner', 32);
            });
            $store = new DatabaseEventStore($connection);
            for ($n = 1; $n <= 120; $n++) {
                $store->append('t-'.($n % 12), 'tally', [new NewEvent('race.happened', ['n' => $n])]);
            }

            $outcomes = projectionRace($lane, $table, 2);
            $positions = array_map(static fn (mixed $p): int => is_numeric($p) ? (int) $p : 0, $connection->table($table)->pluck('global_position')->all());
            sort($positions);

            expect(array_map(static fn (string $line): string => explode(' ', $line)[0], $outcomes))->toBe(['done', 'done'], implode(' | ', $outcomes))
                ->and($positions)->toBe(range(1, 120))
                ->and((new DatabaseCheckpointStore($connection))->get('race.tally')->position)->toBe(120);
        } finally {
            $connection->getSchemaBuilder()->dropIfExists($table);
            $connection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
}

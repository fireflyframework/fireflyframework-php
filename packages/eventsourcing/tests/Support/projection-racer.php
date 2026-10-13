<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Projection\FunctionProjection;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Projection\ProjectionRunResult;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\Testing\Integration\IntegrationDsn;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/*
 * One runner of ProjectionRaceIntegrationTest: a separate OS process with its own connection. It runs with NO
 * lease (the runner's default NoneLock), the worst case: only the checkpoint's fencing keeps two runners from
 * projecting one event twice. The projection writes on the checkpoint's connection, so a fenced batch takes its
 * rows with it. argv: lane, go-file, table[, sqlite path]. It prints "ready", spins until the go-file exists, then
 * catches `race.tally` up until it finds itself at the head twice in a row, and prints what happened. A failed
 * batch (a lock timeout, a busy SQLite file) is counted and retried, as the runner's next cycle would.
 */
$lane = $argv[1] ?? '';
$go = $argv[2] ?? '';
$table = $argv[3] ?? 'firefly_es_race_tally';
$connection = IntegrationDsn::connect($lane, 'projection-racer', isset($argv[4]) ? ['database' => $argv[4]] : []);
$tally = new FunctionProjection('race.tally', static function (StoredEvent $event) use ($connection, $table): void {
    $connection->table($table)->insert(['global_position' => $event->globalPosition, 'runner' => (string) getmypid()]);
});
$runner = new ProjectionRunner(new DatabaseEventStore($connection), new DatabaseCheckpointStore($connection), new ProjectionRegistry([$tally]), batchSize: 7);

fwrite(STDOUT, "ready\n");
$deadline = microtime(true) + 60.0;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(500);
}

try {
    $handled = 0;
    $fenced = 0;
    $failed = 0;
    $atHead = 0;
    $giveUp = microtime(true) + 90.0;
    while ($atHead < 2 && microtime(true) < $giveUp) {
        $result = $runner->catchUp('race.tally');
        $handled += $result->handled;
        $fenced += $result->outcome === ProjectionRunResult::FENCED ? 1 : 0;
        $failed += $result->failed() ? 1 : 0;
        $atHead = $result->outcome === ProjectionRunResult::CAUGHT_UP && $runner->lag('race.tally') === 0 ? $atHead + 1 : 0;
    }
    fwrite(STDOUT, "done {$handled} {$fenced} {$failed}\n");
} catch (Throwable $failure) {
    fwrite(STDOUT, 'error '.$failure::class.': '.$failure->getMessage()."\n");
}

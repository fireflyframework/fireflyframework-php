<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\Testing\Integration\IntegrationDsn;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/*
 * One appender of AppendRaceIntegrationTest: a separate OS process with its own connection, exactly what two web
 * workers on two nodes are. argv: lane, mode (one|many), go-file, aggregate id[, sqlite path]. It prints "ready",
 * spins until the parent creates the go-file (so every racer starts together), appends, and prints what happened.
 */
$lane = $argv[1] ?? '';
$mode = $argv[2] ?? 'one';
$go = $argv[3] ?? '';
$aggregateId = $argv[4] ?? 'race';
$store = new DatabaseEventStore(IntegrationDsn::connect($lane, 'racer', isset($argv[5]) ? ['database' => $argv[5]] : []));
$events = static fn (int $count): array => array_map(static fn (int $n): NewEvent => new NewEvent('race.happened', ['n' => $n, 'by' => getmypid()]), range(1, $count));

fwrite(STDOUT, "ready\n");
$deadline = microtime(true) + 60.0;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(500);
}

try {
    if ($mode === 'one') {
        $store->append($aggregateId, 'race', $events(3), 0);
        fwrite(STDOUT, "won\n");
    } else {
        for ($batch = 0; $batch < 5; $batch++) {
            $store->append($aggregateId, 'race', $events(5), $batch * 5);
        }
        fwrite(STDOUT, "appended 25\n");
    }
} catch (ConcurrencyException) {
    fwrite(STDOUT, "conflict\n");
} catch (Throwable $failure) {
    fwrite(STDOUT, 'error '.$failure::class.': '.$failure->getMessage()."\n");
}

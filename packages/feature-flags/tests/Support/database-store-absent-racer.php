<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Tests\Support\DatabaseStoreIntegration;
use Illuminate\Database\QueryException;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$connection = DatabaseStoreIntegration::connection($argv[1] ?? '', $argv[2] ?? '');
$outer = str_starts_with($argv[4] ?? '', 'outer');
if ($outer) {
    $connection->beginTransaction();
    $connection->table('caller_markers')->insert(['actor' => $argv[3] ?? '']);
}
if (($argv[4] ?? '') === 'outer-snapshot') {
    $connection->table('caller_markers')->count();
}
$first = true;
$store = new DatabaseFlagStore($connection, static function () use (&$first): DateTimeImmutable {
    if (! $first) {
        return new DateTimeImmutable('2026-10-01T12:00:00Z');
    }
    $first = false;
    // Both writers stop before insertion; create-only put relies on the primary key, without a read.
    fwrite(STDOUT, "inserting\n");
    fflush(STDOUT);
    if (fgets(STDIN) !== "insert\n") {
        throw new RuntimeException('Missing insertion barrier release.');
    }

    return new DateTimeImmutable('2026-10-01T12:00:00Z');
});
try {
    $store->put('absent-race', ['state' => 'ENABLED', 'variants' => ['on' => ($argv[3] ?? '') === 'first'], 'defaultVariant' => 'on'], $argv[3] ?? '', ($argv[5] ?? '') === 'null' ? null : 0);
    $outcome = 'success';
} catch (FlagStoreConflict) {
    $outcome = 'conflict';
} catch (Throwable $error) {
    $cause = $error;
    while (! $cause instanceof QueryException && $cause->getPrevious() !== null) {
        $cause = $cause->getPrevious();
    }
    if (($argv[6] ?? '') !== 'native-abort' || ! $cause instanceof QueryException || ($cause->errorInfo[1] ?? null) !== 1020) {
        throw $error;
    }
    // Refresh PDO's server status; stale transaction counters are not proof that a caller can commit.
    $connection->statement('SELECT 1');
    try {
        $connection->commit();
        throw new RuntimeException('Engine-aborted caller transaction unexpectedly committed.');
    } catch (PDOException $commitError) {
        if (! str_contains($commitError->getMessage(), 'no active transaction')) {
            throw $commitError;
        }
    }
    fwrite(STDOUT, "native-abort\n");
    exit(0);
}
if ($outer) {
    if ($connection->transactionLevel() !== 1) {
        throw new RuntimeException('Caller transaction was lost.');
    }
    $connection->statement('SELECT 1');
    $connection->commit();
}
fwrite(STDOUT, $outcome."\n");

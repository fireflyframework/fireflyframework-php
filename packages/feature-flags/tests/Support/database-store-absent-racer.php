<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Store\FlagStoreTransactionAborted;
use Firefly\FeatureFlags\Tests\Support\DatabaseStoreIntegration;
use Illuminate\Database\QueryException;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$arguments = $argv ?? [];

$connection = DatabaseStoreIntegration::connection($arguments[1] ?? '', $arguments[2] ?? '');
$outer = str_starts_with($arguments[4] ?? '', 'outer');
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
$calls = [];
$nativeCause = null;
$runs = 0;
$write = static function () use ($connection, $store, $outer, $arguments, &$calls, &$nativeCause, &$runs): string {
    $runs++;
    if ($outer) {
        $connection->table('caller_markers')->insert(['actor' => $arguments[3] ?? '']);
        if (($arguments[4] ?? '') === 'outer-snapshot') {
            $connection->table('caller_markers')->count();
        }
        $store->afterCommit(static function () use (&$calls): void {
            $calls[] = 'before';
        });
    }
    try {
        $store->put('absent-race', ['state' => 'ENABLED', 'variants' => ['on' => ($arguments[3] ?? '') === 'first'], 'defaultVariant' => 'on'], $arguments[3] ?? '', ($arguments[5] ?? '') === 'null' ? null : 0);

        return 'success';
    } catch (FlagStoreConflict) {
        return 'conflict';
    } catch (Throwable $error) {
        $cause = $error;
        while (! $cause instanceof QueryException && $cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }
        if (($arguments[6] ?? '') !== 'native-abort' || ! $cause instanceof QueryException || ($cause->errorInfo[1] ?? null) !== 1020) {
            throw $error;
        }
        $nativeCause = $cause;

        return 'native-abort';
    }
};
try {
    if (! $outer) {
        $outcome = $write();
    } elseif (($arguments[7] ?? '') === 'callback') {
        $outcome = $connection->transaction($write, 3);
    } else {
        $connection->beginTransaction();
        $outcome = $write();
        $connection->commit();
    }
    if ($nativeCause !== null) {
        throw new RuntimeException('Engine-aborted caller transaction unexpectedly committed.');
    }
} catch (FlagStoreTransactionAborted $commitError) {
    if ($nativeCause === null || $commitError->getPrevious() !== $nativeCause || $calls !== [] || $runs !== 1) {
        throw new RuntimeException('Native abort lost its cause, emitted callbacks or replayed the caller.');
    }
    $store->afterCommit(static function () use (&$calls): void {
        $calls[] = 'aborted';
    });
    $other = DatabaseStoreIntegration::connection($arguments[1] ?? '', $arguments[2] ?? '');
    $dispatcher = $connection->getEventDispatcher();
    if ($dispatcher !== null) {
        $other->setEventDispatcher($dispatcher);
    }
    $otherStore = new DatabaseFlagStore($other);
    $otherCalls = 0;
    $other->beginTransaction();
    $other->table('recovery_markers')->insert(['actor' => 'other']);
    $otherStore->afterCommit(static function () use (&$otherCalls): void {
        $otherCalls++;
    });
    $other->commit();
    if ($otherCalls !== 1 || $calls !== []) {
        throw new RuntimeException('Native abort leaked to another connection or emitted aborted callbacks.');
    }
    $connection->rollBack(0);
    $connection->beginTransaction();
    $connection->table('recovery_markers')->insert(['actor' => 'recovered']);
    $store->afterCommit(static function () use (&$calls): void {
        $calls[] = 'recovered';
    });
    $connection->commit();
    if ($calls !== ['recovered']) {
        throw new RuntimeException('Callbacks did not recover cleanly in the new root transaction.');
    }
    $outcome = 'native-abort';
}
fwrite(STDOUT, $outcome."\n");

<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\Process\Process;

/**
 * THE PROOF the program asks for: two consumers — or two relays — in two OS processes race over the same 200 committed
 * rows, on every server of the matrix, with the strategy `auto` picks AND with the portable optimistic lease forced.
 * Each row must be delivered exactly once in the run (the union of what both printed is 1..200 with no duplicate), no
 * row may be lost, and every row must end PUBLISHED with its lease cleared. On sqlite `auto` IS the optimistic lease,
 * so it runs once.
 */

/**
 * Waits for a racer's "ready" line by reading its ACCUMULATED output. Not Process::waitUntil(): that first calls
 * updateStatus(), which drains the pipes into the output buffer without handing the drained bytes to the callback —
 * so the second racer, whose "ready" arrived while the parent waited on the first, never matched, the go-file was
 * never created, and both racers idled to their 90 s deadline.
 */
function outboxRaceAwaitReady(Process $process, float $timeoutSeconds = 60.0): bool
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

/** Removes the files that exist — an `@unlink` of a missing file is still a warning the test run reports. */
function outboxRaceRemoveFiles(string ...$paths): void
{
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
foreach (IntegrationDsn::lanes() as $lane) {
    foreach (['relay', 'consume'] as $mode) {
        foreach (['auto', 'optimistic-lease'] as $strategy) {
            if ($lane === 'sqlite' && $strategy === 'auto') {
                continue;
            }

            it("delivers every row exactly once with two racing {$mode} processes on {$lane} ({$strategy})", function () use ($lane, $mode, $strategy): void {
                $path = IntegrationDsn::sqlitePath('outbox-race');
                if ($lane === 'sqlite') {
                    outboxRaceRemoveFiles($path, $path.'-wal', $path.'-shm');
                }
                $overrides = $lane === 'sqlite' ? ['database' => $path] : [];
                $connection = IntegrationDsn::connect($lane, 'race-parent', $overrides);
                $schema = $connection->getSchemaBuilder();
                $schema->dropIfExists(OutboxSchema::TABLE);
                $schema->create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));

                $publisher = new DatabaseEventPublisher($connection, new SubscriberRegistry);
                $connection->transaction(function () use ($publisher): void {
                    foreach (range(1, 200) as $n) {
                        $publisher->publish('orders', 'order.placed', ['n' => $n]);
                    }
                });

                $go = sys_get_temp_dir().'/firefly-outbox-race-'.bin2hex(random_bytes(6));
                $racers = [];
                foreach (['racer-a', 'racer-b'] as $claimant) {
                    $arguments = [PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__).'/Support/outbox-racer.php', $lane, $mode, $strategy, $go, $claimant];
                    if ($lane === 'sqlite') {
                        $arguments[] = $path;
                    }
                    $process = new Process($arguments, timeout: 120);
                    $process->start();
                    $racers[] = $process;
                }

                try {
                    foreach ($racers as $process) {
                        expect(outboxRaceAwaitReady($process))->toBeTrue($process->getErrorOutput());
                    }
                    touch($go);

                    $delivered = [];
                    foreach ($racers as $process) {
                        expect($process->wait())->toBe(0, $process->getErrorOutput());
                        preg_match_all('/^delivered (\d+)$/m', $process->getOutput(), $matches);
                        foreach ($matches[1] as $n) {
                            $delivered[] = (int) $n;
                        }
                    }
                    sort($delivered);

                    expect($delivered)->toBe(range(1, 200))
                        ->and($connection->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->count())->toBe(200)
                        ->and($connection->table(OutboxSchema::TABLE)->whereNotNull('claimed_by')->count())->toBe(0);
                } finally {
                    foreach ($racers as $process) {
                        $process->stop();
                    }
                    outboxRaceRemoveFiles($go);
                    $schema->dropIfExists(OutboxSchema::TABLE);
                    $connection->disconnect();
                    if ($lane === 'sqlite') {
                        outboxRaceRemoveFiles($path, $path.'-wal', $path.'-shm');
                    }
                }
            })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
        }
    }
}

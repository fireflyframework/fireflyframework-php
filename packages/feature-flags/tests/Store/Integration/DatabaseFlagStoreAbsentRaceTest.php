<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Firefly\FeatureFlags\Tests\Support\DatabaseStoreIntegration;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

foreach (['Postgres' => ['pgsql', 'FIREFLY_PG_DSN'], 'Mysql' => ['mysql', 'FIREFLY_MYSQL_DSN'], 'MariaDB' => ['mysql', 'FIREFLY_MARIADB_DSN']] as $backend => [$driver, $variable]) {
    foreach (['root', 'outer', 'outer-snapshot'] as $ownership) {
        foreach ([['0', 0], ['null', 0], ['null', 1]] as [$expected, $seed]) {
            it("resolves simultaneous writes from version {$seed} atomically with expected {$expected} in {$ownership} transactions on {$backend}", function () use ($driver, $variable, $ownership, $expected, $backend, $seed): void {
                $connection = DatabaseStoreIntegration::connection($driver, $variable);
                FeatureFlagSchema::drop($connection->getSchemaBuilder());
                FeatureFlagSchema::create($connection->getSchemaBuilder());
                $connection->getSchemaBuilder()->create('caller_markers', static function (Blueprint $table): void {
                    $table->string('actor')->primary();
                });
                if ($seed === 1) {
                    (new DatabaseFlagStore($connection))->put('absent-race', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'initial', 0);
                }
                $snapshot = $backend === 'MariaDB' ? $connection->selectOne('SELECT @@innodb_snapshot_isolation AS enabled') : null;
                $nativeAbort = (is_object($snapshot) && (bool) (get_object_vars($snapshot)['enabled'] ?? false)) && $ownership !== 'root' && ($expected === 'null' || $ownership === 'outer-snapshot');
                $inputs = [new InputStream, new InputStream];
                $processes = [];
                foreach (['first', 'second'] as $index => $actor) {
                    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Support/database-store-absent-racer.php', $driver, $variable, $actor, $ownership, $expected, $nativeAbort ? 'native-abort' : 'conflict'], timeout: 20);
                    $process->setInput($inputs[$index]);
                    $processes[] = $process;
                }
                try {
                    foreach ($processes as $process) {
                        $process->start();
                    }
                    // Neither mutation is released until both transactions reach the post-read / direct-insert boundary.
                    foreach ($processes as $process) {
                        while (! str_contains($process->getOutput(), "inserting\n") && $process->isRunning()) {
                            $process->checkTimeout();
                        }
                        expect($process->getOutput())->toContain("inserting\n");
                    }
                    foreach ($inputs as $input) {
                        $input->write("insert\n");
                        $input->close();
                    }
                    do {
                        $running = false;
                        foreach ($processes as $process) {
                            $running = $process->isRunning() || $running;
                            $process->checkTimeout();
                        }
                    } while ($running);
                    $outcomes = [];
                    foreach ($processes as $process) {
                        expect($process->wait())->toBe(0, $process->getErrorOutput());
                        $outcomes[] = trim(str_replace("inserting\n", '', $process->getOutput()));
                    }
                    sort($outcomes);
                    $writes = $expected === 'null' && $ownership === 'root' ? 2 : 1;
                    $loser = $nativeAbort ? 'native-abort' : 'conflict';
                    expect($connection->table('caller_markers')->count())->toBe($ownership === 'root' ? 0 : ($nativeAbort ? 1 : 2));
                    $store = new DatabaseFlagStore($connection);
                    expect($outcomes)->toBe($writes === 2 ? ['success', 'success'] : [$loser, 'success'])
                        ->and($connection->table(FeatureFlagSchema::FLAGS)->count())->toBe(1)
                        ->and($connection->table(FeatureFlagSchema::CHANGES)->count())->toBe($writes + $seed)
                        ->and($store->get('absent-race')?->version)->toBe($writes + $seed)
                        ->and($store->history('absent-race')[0]->actor)->toBe($store->get('absent-race')?->updatedBy)
                        ->and($store->history('absent-race')[$writes + $seed - 1]->previous)->toBeNull();
                    if ($seed === 0) {
                        $vector = featureFlagsConcurrencyVector(strtolower($backend === 'Postgres' ? 'postgresql' : $backend), $ownership, $expected === 'null' ? null : 0);
                        $outcome = $writes === 2 ? 'two-writes' : ($nativeAbort ? 'one-parent-abort' : 'one-conflict');
                        expect([
                            'outcome' => $outcome,
                            'version' => $store->get('absent-race')?->version,
                            'auditRows' => $connection->table(FeatureFlagSchema::CHANGES)->count(),
                            'callerMarkers' => $connection->table('caller_markers')->count(),
                        ])->toBe($vector['expect']);
                        $isolation = $connection->selectOne($driver === 'pgsql' ? 'SHOW transaction_isolation' : ($backend === 'MariaDB' ? 'SELECT @@tx_isolation AS transaction_isolation' : 'SELECT @@transaction_isolation AS transaction_isolation'));
                        $level = is_object($isolation) ? (get_object_vars($isolation)['transaction_isolation'] ?? null) : null;
                        expect(is_string($level) ? strtoupper(str_replace('-', ' ', $level)) : null)->toBe($vector['isolation'])
                            ->and(is_object($snapshot) && (bool) (get_object_vars($snapshot)['enabled'] ?? false))->toBe($vector['snapshotIsolation']);
                    }
                    if ($writes + $seed > 1) {
                        expect($store->history('absent-race')[0]->previous)->toBe($store->history('absent-race')[1]->definition);
                    }
                } finally {
                    foreach ($processes as $process) {
                        $process->stop();
                    }
                    $connection->getSchemaBuilder()->drop('caller_markers');
                    FeatureFlagSchema::drop($connection->getSchemaBuilder());
                }
            })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated server.");
        }
    }
}

/** @return array{isolation: string, snapshotIsolation: bool, expect: array{outcome: string, version: int, auditRows: int, callerMarkers: int}} */
function featureFlagsConcurrencyVector(string $backend, string $ownership, ?int $expected): array
{
    $raw = file_get_contents(__DIR__.'/../../Conformance/store-concurrency-vectors.json');
    if ($raw === false) {
        throw new RuntimeException('Concurrency vectors cannot be read.');
    }
    /** @var array{cases: list<array{backend: string, transaction: string, expectedVersion: ?int, isolation: string, snapshotIsolation: bool, expect: array{outcome: string, version: int, auditRows: int, callerMarkers: int}}>} $data */
    $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['cases'] as $case) {
        if ($case['backend'] === $backend && $case['transaction'] === $ownership && $case['expectedVersion'] === $expected) {
            return $case;
        }
    }
    throw new RuntimeException('Missing shared concurrency case.');
}

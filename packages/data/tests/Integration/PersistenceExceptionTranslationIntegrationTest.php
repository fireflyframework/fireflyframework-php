<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Kernel\Exception\Infrastructure\BadSqlGrammarException;
use Firefly\Kernel\Exception\Infrastructure\CannotAcquireLockException;
use Firefly\Kernel\Exception\Infrastructure\DataIntegrityViolationException;
use Firefly\Kernel\Exception\Infrastructure\DeadlockLoserDataAccessException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\Process\Process;

/**
 * DriverErrorTable's rows against the errors real servers actually raise — the codes and SQLSTATEs the unit tests can
 * only assume: duplicate key, NOT NULL, foreign key, a missing table, a lock wait that times out, and (on the four
 * servers with row locks) a genuine deadlock between two OS processes.
 */
function translationSchema(Connection $connection): void
{
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists('firefly_translation_children');
    $schema->dropIfExists('firefly_translation_parents');
    $schema->create('firefly_translation_parents', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->string('name');
    });
    $schema->create('firefly_translation_children', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('parent_id');
        $table->string('email')->unique();
        $table->string('name');
        $table->foreign('parent_id')->references('id')->on('firefly_translation_parents');
    });
    $connection->table('firefly_translation_parents')->insert(['id' => 1, 'name' => 'p']);
    $connection->table('firefly_translation_children')->insert(['id' => 1, 'parent_id' => 1, 'email' => 'a@x.test', 'name' => 'c']);
}

function translationOf(Connection $connection, Closure $statement): ?Throwable
{
    try {
        $statement();
    } catch (Throwable $e) {
        return (new PersistenceExceptionTranslator)->translate($e, $connection->getDriverName());
    }

    return null;
}

/** Make $connection give up on a lock wait quickly, in the server's own dialect. */
function translationShortLockWait(Connection $connection, string $lane): void
{
    match ($lane) {
        'pgsql' => $connection->statement("set lock_timeout = '300ms'"),
        'mysql', 'mariadb' => $connection->statement('set session innodb_lock_wait_timeout = 1'),
        // A prepared SET is undone when sp_prepexec returns on SQL Server; only a direct batch sticks to the session.
        'sqlsrv' => $connection->unprepared('set lock_timeout 300'),
        default => null,
    };
}

foreach (IntegrationDsn::lanes() as $lane) {
    it("translates integrity, grammar and lock-wait failures on {$lane}", function () use ($lane): void {
        $overrides = $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('translation'), 'busy_timeout' => 300] : [];
        $a = IntegrationDsn::connect($lane, 'translation-a', $overrides);
        $b = IntegrationDsn::connect($lane, 'translation-b', $overrides);
        translationSchema($a);

        try {
            $children = static fn (Connection $c) => $c->table('firefly_translation_children');

            expect(translationOf($a, static fn () => $children($a)->insert(['id' => 2, 'parent_id' => 1, 'email' => 'a@x.test', 'name' => 'dup'])))->toBeInstanceOf(DuplicateKeyException::class)
                ->and(translationOf($a, static fn () => $children($a)->insert(['id' => 1, 'parent_id' => 1, 'email' => 'b@x.test', 'name' => 'pk'])))->toBeInstanceOf(DuplicateKeyException::class)
                ->and(translationOf($a, static fn () => $children($a)->insert(['id' => 3, 'parent_id' => 1, 'email' => 'c@x.test', 'name' => null])))->toBeInstanceOf(DataIntegrityViolationException::class)
                ->and(translationOf($a, static fn () => $children($a)->insert(['id' => 4, 'parent_id' => 99, 'email' => 'd@x.test', 'name' => 'fk'])))->toBeInstanceOf(DataIntegrityViolationException::class)
                ->and(translationOf($a, static fn () => $a->table('firefly_translation_missing')->get()))->toBeInstanceOf(BadSqlGrammarException::class);

            // A lock wait that times out: a holds the row (sqlite: the database) for writing, b gives up.
            $a->beginTransaction();
            $a->table('firefly_translation_parents')->where('id', 1)->update(['name' => 'held']);
            translationShortLockWait($b, $lane);
            $waited = translationOf($b, static fn () => $b->table('firefly_translation_parents')->where('id', 1)->update(['name' => 'waiter']));
            $a->rollBack();

            expect($waited)->toBeInstanceOf(CannotAcquireLockException::class);
        } finally {
            $schema = $a->getSchemaBuilder();
            $schema->dropIfExists('firefly_translation_children');
            $schema->dropIfExists('firefly_translation_parents');
            $a->disconnect();
            $b->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}

foreach (['pgsql', 'mysql', 'mariadb', 'sqlsrv'] as $lane) {
    it("translates the deadlock victim on {$lane}, whichever process the server picks", function () use ($lane): void {
        $connection = IntegrationDsn::connect($lane, 'deadlock-parent');
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists('firefly_deadlock_probe');
        $schema->create('firefly_deadlock_probe', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $connection->table('firefly_deadlock_probe')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);

        $go = sys_get_temp_dir().'/firefly-deadlock-'.bin2hex(random_bytes(6));
        $racer = new Process([PHP_BINARY, dirname(__DIR__).'/Support/deadlock-racer.php', $lane, $go], timeout: 40);

        try {
            $connection->beginTransaction();
            $connection->table('firefly_deadlock_probe')->where('id', 1)->update(['name' => 'parent']);
            $racer->start();
            expect($racer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')))->toBeTrue($racer->getErrorOutput());
            touch($go);
            usleep(300_000); // the racer is now waiting for row 1

            $parent = translationOf($connection, static fn () => $connection->table('firefly_deadlock_probe')->where('id', 2)->update(['name' => 'parent']));
            if ($parent === null) {
                $connection->commit();
            } elseif ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            expect($racer->wait())->toBe(0, $racer->getErrorOutput());
            $racerSaw = trim($racer->getOutput());

            // Exactly one side lost, and it was told so in the kernel's own vocabulary.
            expect($parent instanceof DeadlockLoserDataAccessException xor str_ends_with($racerSaw, 'deadlock'))->toBeTrue('parent: '.($parent === null ? 'committed' : $parent::class)."; racer: {$racerSaw}")
                ->and($parent === null || $parent instanceof DeadlockLoserDataAccessException)->toBeTrue();
        } finally {
            $racer->stop();
            @unlink($go);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $schema->dropIfExists('firefly_deadlock_probe');
            $connection->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}

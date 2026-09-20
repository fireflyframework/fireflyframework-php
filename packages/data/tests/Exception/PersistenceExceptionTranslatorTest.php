<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Kernel\Exception\Infrastructure\BadSqlGrammarException;
use Firefly\Kernel\Exception\Infrastructure\CannotAcquireLockException;
use Firefly\Kernel\Exception\Infrastructure\DataAccessException;
use Firefly\Kernel\Exception\Infrastructure\DataAccessResourceFailureException;
use Firefly\Kernel\Exception\Infrastructure\DataIntegrityViolationException;
use Firefly\Kernel\Exception\Infrastructure\DeadlockLoserDataAccessException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Firefly\Kernel\Exception\Infrastructure\QueryTimeoutException;
use Firefly\Kernel\Exception\Infrastructure\TransientDataAccessResourceException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A QueryException the way Laravel builds one: the PDOException underneath carries errorInfo, and the
 * QueryException copies it. $code is what PDO puts in errorInfo[1]; $sqlState is errorInfo[0].
 *
 * @param  list<mixed>  $bindings
 */
function queryException(string $sqlState, int $code, string $message, string $sql = 'insert into t (id) values (?)', array $bindings = [1]): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
    $pdo->errorInfo = [$sqlState, $code, $message];

    return new QueryException('default', $sql, $bindings, $pdo);
}

it('translates by driver code, wraps the original and keeps the SQL off the message', function () {
    $translator = new PersistenceExceptionTranslator;
    $original = queryException('23000', 1062, "Integrity constraint violation: 1062 Duplicate entry 'a' for key 'users.email'", 'insert into users (email) values (?)', ['a@x.test']);

    $translated = $translator->translate($original, 'mysql');

    expect($translated)->toBeInstanceOf(DuplicateKeyException::class)
        ->and($translated->getPrevious())->toBe($original)
        ->and($translated->getMessage())->toBe('A row with the same unique key already exists.')
        ->and($translated->getMessage())->not->toContain('insert into')
        ->and($translated->getMessage())->not->toContain('a@x.test');

    assert($translated instanceof DuplicateKeyException);
    expect($translated->extensions())->toBe(['sqlState' => '23000'])
        ->and($translated->httpStatus())->toBe(409);
});

// The EXACT class, not instanceof: 'sqlite not null' must be a DataIntegrityViolationException and not the
// DuplicateKeyException below it, and 'mysql lock wait' a CannotAcquireLockException and not a deadlock.
it('translates every kind to its exception', function (string $driver, string $sqlState, int $code, string $message, string $expected) {
    $translated = (new PersistenceExceptionTranslator)->translate(queryException($sqlState, $code, $message), $driver);

    expect($translated::class)->toBe($expected)
        ->and($translated->getPrevious())->toBeInstanceOf(QueryException::class);
})->with([
    'mysql deadlock' => ['mysql', '40001', 1213, 'Deadlock found when trying to get lock', DeadlockLoserDataAccessException::class],
    'mysql lock wait' => ['mysql', 'HY000', 1205, 'Lock wait timeout exceeded', CannotAcquireLockException::class],
    'mysql statement timeout' => ['mysql', 'HY000', 3024, 'Query execution was interrupted, maximum statement execution time exceeded', QueryTimeoutException::class],
    'mysql refused' => ['mysql', 'HY000', 2002, 'Connection refused', DataAccessResourceFailureException::class],
    'mysql gone away' => ['mysql', 'HY000', 2006, 'MySQL server has gone away', TransientDataAccessResourceException::class],
    'mysql syntax' => ['mysql', '42000', 1064, 'You have an error in your SQL syntax', BadSqlGrammarException::class],
    'mysql fk' => ['mysql', '23000', 1452, 'Cannot add or update a child row', DataIntegrityViolationException::class],
    'pgsql unique' => ['pgsql', '23505', 7, 'duplicate key value violates unique constraint', DuplicateKeyException::class],
    'pgsql fk' => ['pgsql', '23503', 7, 'violates foreign key constraint', DataIntegrityViolationException::class],
    'pgsql not null' => ['pgsql', '23502', 7, 'null value in column violates not-null constraint', DataIntegrityViolationException::class],
    'pgsql deadlock' => ['pgsql', '40P01', 7, 'deadlock detected', DeadlockLoserDataAccessException::class],
    'pgsql nowait' => ['pgsql', '55P03', 7, 'could not obtain lock on row', CannotAcquireLockException::class],
    'pgsql canceled' => ['pgsql', '57014', 7, 'canceling statement due to statement timeout', QueryTimeoutException::class],
    'pgsql connection' => ['pgsql', '08006', 7, 'server closed the connection unexpectedly', DataAccessResourceFailureException::class],
    'pgsql syntax' => ['pgsql', '42601', 7, 'syntax error at or near', BadSqlGrammarException::class],
    'sqlsrv unique' => ['sqlsrv', '23000', 2627, 'Violation of UNIQUE KEY constraint', DuplicateKeyException::class],
    'sqlsrv dup index' => ['sqlsrv', '23000', 2601, 'Cannot insert duplicate key row', DuplicateKeyException::class],
    'sqlsrv deadlock' => ['sqlsrv', '40001', 1205, 'was deadlocked on lock resources', DeadlockLoserDataAccessException::class],
    'sqlsrv fk' => ['sqlsrv', '23000', 547, 'conflicted with the FOREIGN KEY constraint', DataIntegrityViolationException::class],
    'sqlite busy' => ['sqlite', 'HY000', 5, 'database is locked', CannotAcquireLockException::class],
    'sqlite locked' => ['sqlite', 'HY000', 6, 'database table is locked', CannotAcquireLockException::class],
    'sqlite no such table' => ['sqlite', 'HY000', 1, 'no such table: nope', BadSqlGrammarException::class],
    'sqlite not null' => ['sqlite', '23000', 19, 'NOT NULL constraint failed: t.email', DataIntegrityViolationException::class],
    'sqlite fk' => ['sqlite', '23000', 19, 'FOREIGN KEY constraint failed', DataIntegrityViolationException::class],
]);

it('reads the duplicate-key distinction off the sqlite message, since PDO never reports the extended code', function () {
    $translator = new PersistenceExceptionTranslator;

    expect($translator->translate(queryException('23000', 19, 'UNIQUE constraint failed: t.email'), 'sqlite'))->toBeInstanceOf(DuplicateKeyException::class)
        ->and($translator->translate(queryException('23000', 19, 'PRIMARY KEY must be unique'), 'sqlite'))->toBeInstanceOf(DuplicateKeyException::class)
        ->and($translator->translate(queryException('23000', 19, 'columns a, b are not unique'), 'sqlite'))->toBeInstanceOf(DuplicateKeyException::class)
        ->and($translator->translate(queryException('23000', 19, 'CHECK constraint failed: positive'), 'sqlite'))->not->toBeInstanceOf(DuplicateKeyException::class);
});

it('honours the typed exceptions Laravel already raises, before any table lookup', function () {
    $translator = new PersistenceExceptionTranslator;
    $pdo = new PDOException('UNIQUE constraint failed: t.email');
    $pdo->errorInfo = ['23000', 19, 'UNIQUE constraint failed: t.email'];

    expect($translator->translate(new UniqueConstraintViolationException('default', 'insert', [], $pdo)))->toBeInstanceOf(DuplicateKeyException::class)
        ->and($translator->translate(new SQLiteDatabaseDoesNotExistException('/nope/db.sqlite')))->toBeInstanceOf(DataAccessResourceFailureException::class)
        ->and($translator->translate(new LostConnectionException('server has gone away')))->toBeInstanceOf(TransientDataAccessResourceException::class)
        ->and($translator->translate(new DeadlockException('deadlock')))->toBeInstanceOf(DeadlockLoserDataAccessException::class);
});

it('maps a connect-time sqlite failure whose code is the exception code itself', function () {
    $translated = (new PersistenceExceptionTranslator)->translate(new PDOException('SQLSTATE[HY000] [14] unable to open database file', 14), 'sqlite');

    expect($translated)->toBeInstanceOf(DataAccessResourceFailureException::class);
});

it('wraps an unclassifiable database error as the generic DataAccessException', function () {
    $translated = (new PersistenceExceptionTranslator)->translate(queryException('XX000', 7, 'internal error'), 'pgsql');

    expect($translated)->toBeInstanceOf(DataAccessException::class)
        ->and($translated::class)->toBe(DataAccessException::class)
        ->and($translated->getMessage())->toBe('The database refused the operation.');
});

it('leaves what is not a database error, and what is already translated, alone', function () {
    $translator = new PersistenceExceptionTranslator;
    $runtime = new RuntimeException('not a db error');
    $already = new DuplicateKeyException;

    expect($translator->translate($runtime))->toBe($runtime)
        ->and($translator->translate($already))->toBe($already);
});

it('passes everything through when disabled', function () {
    $translator = new PersistenceExceptionTranslator(enabled: false);
    $original = queryException('23000', 1062, 'Duplicate entry');

    expect($translator->isEnabled())->toBeFalse()
        ->and($translator->translate($original, 'mysql'))->toBe($original);
});

<?php

declare(strict_types=1);

namespace Firefly\Data\Exception;

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
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Spring's PersistenceExceptionTranslator: a driver's failure becomes a member of the kernel's
 * DataAccessException family, with the original as `previous`. Applied at the three seams a database error
 * can leave firefly/data through — every EloquentRepository method, TransactionTemplate::execute() (and so
 * every #[Transactional] proxy, which delegates to it), and nothing else, so a raw `DB::` call outside both
 * still throws Laravel's QueryException exactly as before.
 *
 * WHAT IT WILL NOT TOUCH: a throwable that is already in the family (idempotent — the repository translates
 * inside a template that translates again), anything that is not a PDOException (Laravel's QueryException
 * IS one) unless it is one of the four typed exceptions Laravel itself raises, and everything when the
 * `firefly.data.exception-translation.enabled` key is false.
 *
 * The four typed exceptions are looked for on the throwable AND, when it is a QueryException, on the cause
 * underneath it: Connection::runQueryCallback() wraps everything the callback throws, and the lazy PDO
 * resolver runs inside that callback, so a missing sqlite file (SQLiteDatabaseDoesNotExistException) or a
 * connection lost mid-statement (LostConnectionException) arrives as a QueryException with code 0 and no
 * errorInfo — unclassifiable by any table — and the typed cause is the only evidence of what happened.
 *
 * WHY THE MESSAGE IS A FIXED SENTENCE: a FireflyException's message is the problem document's `detail`, and
 * a QueryException's message is the statement with its bindings interpolated — an email address, a token, a
 * tenant id. The driver's text stays on `previous` for the log; the SQLSTATE, harmless and useful, rides as
 * the `sqlState` extension member.
 *
 * The driver name decides which code table applies. Callers that have a Connection pass its getDriverName();
 * a bare call resolves it from the QueryException's connection name through the DB facade, and falls back to
 * the SQLSTATE tables alone when there is no application (a unit test) or no such connection.
 */
final class PersistenceExceptionTranslator
{
    /** sqlite reports every constraint as code 19; the unique/primary-key family is only visible in the message. */
    private const string SQLITE_UNIQUE = '/UNIQUE constraint failed|PRIMARY KEY must be unique|columns? .* (is|are) not unique/i';

    public function __construct(private readonly bool $enabled = true) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function translate(Throwable $e, ?string $driver = null): Throwable
    {
        if (! $this->enabled || $e instanceof DataAccessException) {
            return $e;
        }

        // Laravel already classified these four; its answer is at least as good as the tables'. A QueryException
        // may be carrying one of them as its cause (see the class docblock); the wrapper stays `previous`.
        $cause = $e instanceof QueryException ? $e->getPrevious() : null;

        if ($e instanceof UniqueConstraintViolationException) {
            return self::build(DriverErrorTable::DUPLICATE_KEY, $e, self::sqlState($e));
        }
        if ($e instanceof SQLiteDatabaseDoesNotExistException || $cause instanceof SQLiteDatabaseDoesNotExistException) {
            return self::build(DriverErrorTable::RESOURCE, $e, null);
        }
        if ($e instanceof LostConnectionException || $cause instanceof LostConnectionException) {
            return self::build(DriverErrorTable::TRANSIENT, $e, null);
        }
        if ($e instanceof DeadlockException || $cause instanceof DeadlockException) {
            return self::build(DriverErrorTable::DEADLOCK, $e, self::sqlState($e));
        }

        if (! $e instanceof PDOException) {
            return $e;
        }

        $driver ??= $e instanceof QueryException ? self::driverOf($e->getConnectionName()) : null;
        $sqlState = self::sqlState($e);
        $kind = DriverErrorTable::kindFor($driver, self::driverCode($e), $sqlState);

        if ($kind === DriverErrorTable::INTEGRITY && $driver === 'sqlite' && preg_match(self::SQLITE_UNIQUE, $e->getMessage()) === 1) {
            $kind = DriverErrorTable::DUPLICATE_KEY;
        }

        return self::build($kind, $e, $sqlState);
    }

    private static function build(?string $kind, Throwable $cause, ?string $sqlState): DataAccessException
    {
        $translated = match ($kind) {
            DriverErrorTable::DUPLICATE_KEY => new DuplicateKeyException(previous: $cause),
            DriverErrorTable::INTEGRITY => new DataIntegrityViolationException(previous: $cause),
            DriverErrorTable::DEADLOCK => new DeadlockLoserDataAccessException(previous: $cause),
            DriverErrorTable::LOCK => new CannotAcquireLockException(previous: $cause),
            DriverErrorTable::TIMEOUT => new QueryTimeoutException(previous: $cause),
            DriverErrorTable::TRANSIENT => new TransientDataAccessResourceException(previous: $cause),
            DriverErrorTable::RESOURCE => new DataAccessResourceFailureException(previous: $cause),
            DriverErrorTable::GRAMMAR => new BadSqlGrammarException(previous: $cause),
            default => new DataAccessException('The database refused the operation.', previous: $cause),
        };

        return $sqlState === null ? $translated : $translated->withExtensions(['sqlState' => $sqlState]);
    }

    /** errorInfo[0] when PDO filled it, else a five-character exception code (PDO puts the SQLSTATE there too). */
    private static function sqlState(Throwable $e): ?string
    {
        if ($e instanceof PDOException) {
            $info = $e->errorInfo;
            if (is_array($info) && isset($info[0]) && is_string($info[0]) && $info[0] !== '' && $info[0] !== '00000') {
                return $info[0];
            }
        }

        $code = (string) $e->getCode();

        return preg_match('/^[0-9A-Z]{5}$/', $code) === 1 ? $code : null;
    }

    /** errorInfo[1] when PDO filled it; else the exception code itself, which is how a connect-time sqlite failure (14) arrives. */
    private static function driverCode(PDOException $e): ?int
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && is_int($info[1])) {
            return $info[1];
        }

        $code = (string) $e->getCode();

        return $code !== '' && ctype_digit($code) && strlen($code) !== 5 ? (int) $code : null;
    }

    private static function driverOf(string $connection): ?string
    {
        try {
            return DB::connection($connection)->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }
}

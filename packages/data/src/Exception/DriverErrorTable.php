<?php

declare(strict_types=1);

namespace Firefly\Data\Exception;

/**
 * THE error tables — the one place a driver's error code or a SQLSTATE is turned into a kind of failure.
 * PersistenceExceptionTranslator turns the kind into the kernel exception; DriverErrorTableTest carries one
 * row per row here and a count guard, so a row added without its test fails the gate.
 *
 * Resolution order (kindFor): the driver's own code when the driver is known — because the same SQLSTATE
 * hides different failures (mysql 1062 duplicate and 1452 foreign key both arrive as 23000) and the same
 * number means different things per driver (1205 is a lock wait on mysql, a deadlock victim on sqlsrv) —
 * then the exact SQLSTATE, then its two-character class in Spring's SQLStateSQLExceptionTranslator buckets,
 * then null (the translator's generic DataAccessException).
 *
 * sqlite: PDO reports the PRIMARY result code (19 for every constraint) and never the extended 1555/2067,
 * so the unique/primary-key distinction cannot come from here — the translator reads it off the message
 * (Laravel's own UniqueConstraintViolationException does the same). The 1555/2067 rows are kept for a PDO
 * that does report extended codes. pgsql puts a constant 7 in the driver-code slot for every error, so it
 * has no driver rows and resolves by SQLSTATE alone.
 */
final class DriverErrorTable
{
    public const string DUPLICATE_KEY = 'duplicate-key';

    public const string INTEGRITY = 'integrity';

    public const string DEADLOCK = 'deadlock';

    public const string LOCK = 'lock';

    public const string TIMEOUT = 'timeout';

    public const string TRANSIENT = 'transient';

    public const string RESOURCE = 'resource';

    public const string GRAMMAR = 'grammar';

    /**
     * Shared by mysql and mariadb: mariadb speaks the mysql error vocabulary, and its own numbers (the 19xx
     * range) are unused by mysql — so a `mysql`-configured connection that is really talking to mariadb,
     * the way one was set up before Laravel 11 had a `mariadb` driver, translates those too.
     *
     * @var array<int, string>
     */
    private const array MYSQL_CODES = [
        1062 => self::DUPLICATE_KEY,  // ER_DUP_ENTRY
        1213 => self::DEADLOCK,       // ER_LOCK_DEADLOCK
        1205 => self::LOCK,           // ER_LOCK_WAIT_TIMEOUT
        3024 => self::TIMEOUT,        // ER_QUERY_TIMEOUT (mysql max_execution_time)
        1969 => self::TIMEOUT,        // ER_STATEMENT_TIMEOUT (mariadb max_statement_time)
        2002 => self::RESOURCE,       // CR_CONNECTION_ERROR (refused / socket)
        2006 => self::TRANSIENT,      // CR_SERVER_GONE_ERROR
        2013 => self::TRANSIENT,      // CR_SERVER_LOST
        1064 => self::GRAMMAR,        // ER_PARSE_ERROR
        1146 => self::GRAMMAR,        // ER_NO_SUCH_TABLE
        1054 => self::GRAMMAR,        // ER_BAD_FIELD_ERROR
        1048 => self::INTEGRITY,      // ER_BAD_NULL_ERROR
        1451 => self::INTEGRITY,      // ER_ROW_IS_REFERENCED_2
        1452 => self::INTEGRITY,      // ER_NO_REFERENCED_ROW_2
    ];

    /** @var array<string, array<int, string>> driver name (Connection::getDriverName()) => driver error code => kind */
    public const array DRIVER_CODES = [
        'sqlite' => [
            19 => self::INTEGRITY,       // SQLITE_CONSTRAINT
            1555 => self::DUPLICATE_KEY, // SQLITE_CONSTRAINT_PRIMARYKEY
            2067 => self::DUPLICATE_KEY, // SQLITE_CONSTRAINT_UNIQUE
            5 => self::LOCK,             // SQLITE_BUSY
            6 => self::LOCK,             // SQLITE_LOCKED
            1 => self::GRAMMAR,          // SQLITE_ERROR (syntax, no such table/column)
            14 => self::RESOURCE,        // SQLITE_CANTOPEN
        ],
        'mysql' => self::MYSQL_CODES,
        'mariadb' => self::MYSQL_CODES,
        'sqlsrv' => [
            2627 => self::DUPLICATE_KEY, // unique constraint
            2601 => self::DUPLICATE_KEY, // unique index
            1205 => self::DEADLOCK,      // deadlock victim
            547 => self::INTEGRITY,      // FK / CHECK conflict
            515 => self::INTEGRITY,      // cannot insert NULL
            1222 => self::LOCK,          // lock request timeout
            208 => self::GRAMMAR,        // invalid object name
            102 => self::GRAMMAR,        // incorrect syntax
        ],
    ];

    /**
     * Exact SQLSTATE => kind; consulted before the class map. Keyed `int|string` because PHP turns an
     * integer-like array key such as '23505' into the int 23505 (and leaves '08001' or '40P01' a string) —
     * a lookup casts the same way, so `SQLSTATES['23505']` still finds its row.
     *
     * @var array<int|string, string>
     */
    public const array SQLSTATES = [
        '23505' => self::DUPLICATE_KEY,
        '23503' => self::INTEGRITY,
        '23502' => self::INTEGRITY,
        '23514' => self::INTEGRITY,
        '23000' => self::INTEGRITY,
        '40P01' => self::DEADLOCK,
        '40001' => self::TRANSIENT,
        '55P03' => self::LOCK,
        '57014' => self::TIMEOUT,
        '42601' => self::GRAMMAR,
        '42P01' => self::GRAMMAR,
        '42703' => self::GRAMMAR,
        '42S02' => self::GRAMMAR,
        '42S22' => self::GRAMMAR,
        '42000' => self::GRAMMAR,
        '08001' => self::RESOURCE,
        '08003' => self::RESOURCE,
        '08004' => self::RESOURCE,
        '08006' => self::RESOURCE,
        '08007' => self::RESOURCE,
        '08S01' => self::TRANSIENT,
    ];

    /** @var array<int|string, string> SQLSTATE class (first two characters) => kind; int keys for the same reason as SQLSTATES */
    public const array SQLSTATE_CLASSES = [
        '07' => self::GRAMMAR,
        '21' => self::GRAMMAR,
        '2A' => self::GRAMMAR,
        '37' => self::GRAMMAR,
        '42' => self::GRAMMAR,
        '65' => self::GRAMMAR,
        '22' => self::INTEGRITY,
        '23' => self::INTEGRITY,
        '27' => self::INTEGRITY,
        '44' => self::INTEGRITY,
        '08' => self::RESOURCE,
        '53' => self::RESOURCE,
        '54' => self::RESOURCE,
        '57' => self::RESOURCE,
        '58' => self::RESOURCE,
        '40' => self::TRANSIENT,
        '61' => self::TRANSIENT,
    ];

    public static function kindFor(?string $driver, ?int $driverCode, ?string $sqlState): ?string
    {
        if ($driver !== null && $driverCode !== null && isset(self::DRIVER_CODES[$driver][$driverCode])) {
            return self::DRIVER_CODES[$driver][$driverCode];
        }

        if ($sqlState === null || $sqlState === '') {
            return null;
        }

        $sqlState = strtoupper($sqlState);

        return self::SQLSTATES[$sqlState] ?? self::SQLSTATE_CLASSES[substr($sqlState, 0, 2)] ?? null;
    }
}

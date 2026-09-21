<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Timeout;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use PDO;
use Throwable;

/**
 * The per-driver half of a transaction timeout: tell the DATABASE to give up on a statement that runs past the
 * budget, because the wall-clock check in TransactionTemplate can only judge a method after it returns — it
 * cannot interrupt a statement that is still running. apply() is called right after beginTransaction() on the
 * outermost transaction and returns the step that undoes it, which the template runs in its finally.
 *
 *   pgsql          SET LOCAL statement_timeout = <ms>. LOCAL is scoped to the current transaction — which is
 *                  exactly why it must be issued AFTER the BEGIN — and dies with it, so there is nothing to
 *                  restore.
 *   mysql          SET SESSION max_execution_time = <ms> (read-only SELECTs — the only statements mysql
 *                  interrupts; the kill arrives as error 3024) and SET SESSION innodb_lock_wait_timeout = <s>
 *                  (a lock wait inside the unit of work). SESSION outlives the transaction, so each previous
 *                  value is read first and put back afterwards — on a persistent connection the next request
 *                  would otherwise inherit this one's budget.
 *   mariadb        the same shape over mariadb's OWN variable, SET SESSION max_statement_time = <s> — a double
 *                  in SECONDS that interrupts any statement, not only a SELECT, and kills with error 1969.
 *                  mariadb has no max_execution_time system variable at all (asking for it is error 1193
 *                  "Unknown system variable"), so the mysql statements would silently apply nothing there.
 *                  innodb_lock_wait_timeout is the same on both. A `mysql` connection whose server turns out
 *                  to be mariadb — the way one was configured before Laravel 11 had a `mariadb` driver — takes
 *                  this arm too, detected through the server version exactly as MySqlConnection::isMaria()
 *                  does (an attribute the handshake already filled, not a round trip).
 *   sqlite         PDO::ATTR_TIMEOUT = <s>, the busy timeout — the only knob sqlite has (a statement that is
 *                  merely slow is not interruptible). PDO cannot read the attribute back, so the restore goes
 *                  to the connection's configured `busy_timeout` (ms) or PDO_SQLITE's own 60 s default.
 *   anything else  nothing.
 *
 * Every statement is best-effort, exactly like the isolation SET: a driver that refuses it does not fail the
 * transaction (documented latent — the wall-clock check still applies). On mysql and mariadb the two session
 * variables are read, set and restored INDEPENDENTLY of each other, so a server that lacks one of them (a
 * fork, an old release, a user who may not SET it) still gets the other — and a value that was never read is
 * never "restored".
 */
final class StatementTimeoutApplier
{
    /**
     * @return Closure(): void
     */
    public function apply(Connection $connection, int $seconds): Closure
    {
        $noop = static function (): void {};

        try {
            return match ($connection->getDriverName()) {
                'pgsql' => $this->postgres($connection, $seconds),
                'mysql' => $this->isMaria($connection) ? $this->mariadb($connection, $seconds) : $this->mysql($connection, $seconds),
                'mariadb' => $this->mariadb($connection, $seconds),
                'sqlite' => $this->sqlite($connection, $seconds),
                default => $noop,
            };
        } catch (Throwable) {
            return $noop;
        }
    }

    /**
     * @return Closure(): void
     */
    private function postgres(Connection $connection, int $seconds): Closure
    {
        $connection->statement(sprintf('set local statement_timeout = %d', $seconds * 1000));

        return static function (): void {};
    }

    /**
     * @return Closure(): void
     */
    private function mysql(Connection $connection, int $seconds): Closure
    {
        return self::restoring([
            $this->session($connection, 'max_execution_time', $seconds * 1000),
            $this->session($connection, 'innodb_lock_wait_timeout', $seconds),
        ]);
    }

    /**
     * @return Closure(): void
     */
    private function mariadb(Connection $connection, int $seconds): Closure
    {
        return self::restoring([
            $this->session($connection, 'max_statement_time', (float) $seconds),
            $this->session($connection, 'innodb_lock_wait_timeout', $seconds),
        ]);
    }

    /** A `mysql`-configured connection that is really talking to mariadb: Laravel's own check, off the handshake. */
    private function isMaria(Connection $connection): bool
    {
        return $connection instanceof MySqlConnection && $connection->isMaria();
    }

    /**
     * Reads one session variable, sets it for this unit of work and returns the step that puts the previous
     * value back — or null when the server refused either statement (it does not know the variable, or the
     * user may not SET it), so the caller can still apply its other variables. The PHP type of $value is the
     * variable's SQL type — int for an integer variable, float for mariadb's double max_statement_time — and
     * the restore writes the value read back in that same type: a `0.000000` into an integer variable is
     * ER_WRONG_TYPE_FOR_VAR on mysql, and an integer literal would drop a fractional max_statement_time.
     *
     * @return (Closure(): void)|null
     */
    private function session(Connection $connection, string $variable, int|float $value): ?Closure
    {
        try {
            $row = $connection->selectOne(sprintf('select @@session.%s as value', $variable));
            $connection->statement(sprintf('set session %s = %s', $variable, self::literal($value)));
        } catch (Throwable) {
            return null;
        }

        $previous = is_object($row) ? (get_object_vars($row)['value'] ?? null) : null;

        if (! is_numeric($previous)) {
            // Nothing came back (pretend(), a server that answers nothing): there is no value to put back.
            return null;
        }

        $literal = self::literal(is_float($value) ? (float) $previous : (int) $previous);

        return static function () use ($connection, $variable, $literal): void {
            try {
                $connection->statement(sprintf('set session %s = %s', $variable, $literal));
            } catch (Throwable) {
                // Best-effort restore: the session is about to be released or reused; a failed SET must not
                // turn a successful commit into an exception.
            }
        };
    }

    /**
     * @return Closure(): void
     */
    private function sqlite(Connection $connection, int $seconds): Closure
    {
        $pdo = $connection->getPdo();
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, $seconds);

        $configured = $connection->getConfig('busy_timeout');
        $restoreTo = is_numeric($configured) ? (int) ceil(((int) $configured) / 1000) : 60;

        return static function () use ($pdo, $restoreTo): void {
            try {
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, $restoreTo);
            } catch (Throwable) {
                // Best-effort, see the class docblock.
            }
        };
    }

    /**
     * One step that runs every restore in reverse order of application, skipping the variables that were
     * never set.
     *
     * @param  list<(Closure(): void)|null>  $steps
     * @return Closure(): void
     */
    private static function restoring(array $steps): Closure
    {
        $steps = array_reverse(array_values(array_filter($steps)));

        return static function () use ($steps): void {
            foreach ($steps as $step) {
                $step();
            }
        };
    }

    /**
     * The SQL literal for a value: an integer as-is, a double through the locale-independent %F with its
     * trailing zeros dropped (5.0 is written as the `5` a reader expects, 0.5 stays 0.5, and the six decimals
     * cover max_statement_time's microsecond precision).
     */
    private static function literal(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%F', $value), '0'), '.');
    }
}

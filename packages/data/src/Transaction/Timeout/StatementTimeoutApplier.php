<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Timeout;

use Closure;
use Illuminate\Database\Connection;
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
 *   mysql/mariadb  SET SESSION max_execution_time = <ms> (SELECTs; mariadb honours it as its own alias) and
 *                  SET SESSION innodb_lock_wait_timeout = <s> (a lock wait inside the unit of work). SESSION
 *                  outlives the transaction, so the previous values are read first and put back afterwards —
 *                  on a persistent connection the next request would otherwise inherit this one's budget.
 *   sqlite         PDO::ATTR_TIMEOUT = <s>, the busy timeout — the only knob sqlite has (a statement that is
 *                  merely slow is not interruptible). PDO cannot read the attribute back, so the restore goes
 *                  to the connection's configured `busy_timeout` (ms) or PDO_SQLITE's own 60 s default.
 *   anything else  nothing.
 *
 * Every statement is best-effort, exactly like the isolation SET: a driver that refuses it does not fail the
 * transaction (documented latent — the wall-clock check still applies).
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
                'mysql', 'mariadb' => $this->mysql($connection, $seconds),
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
        $previous = $connection->selectOne('select @@session.max_execution_time as execution, @@session.innodb_lock_wait_timeout as lock_wait');

        $connection->statement(sprintf('set session max_execution_time = %d', $seconds * 1000));
        $connection->statement(sprintf('set session innodb_lock_wait_timeout = %d', $seconds));

        $values = is_object($previous) ? get_object_vars($previous) : [];
        $execution = $values['execution'] ?? null;
        $lockWait = $values['lock_wait'] ?? null;

        return static function () use ($connection, $execution, $lockWait): void {
            try {
                if (is_numeric($execution)) {
                    $connection->statement(sprintf('set session max_execution_time = %d', (int) $execution));
                }
                if (is_numeric($lockWait)) {
                    $connection->statement(sprintf('set session innodb_lock_wait_timeout = %d', (int) $lockWait));
                }
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
}

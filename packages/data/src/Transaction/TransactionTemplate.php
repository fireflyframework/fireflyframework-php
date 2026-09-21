<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;
use Firefly\Data\DataSettings;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Transaction\Exception\TransactionNotAllowedException;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Firefly\Data\Transaction\Exception\TransactionSystemException;
use Firefly\Data\Transaction\Timeout\StatementTimeoutApplier;
use Firefly\Kernel\Exception\Infrastructure\TransactionTimedOutException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single source of truth for transaction semantics — used directly (programmatic twin) and by the
 * TransactionInterceptor behind #[Transactional]. Uses MANUAL DB::connection()->beginTransaction()/commit()/
 * rollBack(), NOT DB::transaction($closure): only manual control lets a caught throwable be COMMITTED (when
 * noRollbackFor matches, or when it is not in rollbackFor) instead of always rolled back. Nested begin()s become
 * savepoints automatically (Laravel), so NESTED unwinds only to its savepoint. Isolation/read-only SET
 * statements are best-effort on a new outermost transaction (drivers vary; SQLite ignores them) — documented
 * latent. REQUIRES_NEW/NOT_SUPPORTED cannot truly suspend an active transaction on the same connection (Laravel
 * has no suspend primitive): REQUIRES_NEW degrades to a savepoint there — documented latent.
 *
 * EXCEPTION TRANSLATION happens here, around every propagation arm, so a #[Transactional] method — whose proxy
 * delegates to execute() through TransactionInterceptor — throws the kernel's DataAccessException family rather
 * than a raw QueryException, whether or not the failing statement went through a repository. The rollback rules
 * are evaluated against the translated exception AND the original underneath it, so a `noRollbackFor:
 * [QueryException::class]` written before translation existed still matches. A commit that itself fails (a
 * deferred constraint, a lost connection) is rolled back if the connection still reports an open transaction,
 * then translated and rethrown — never left open. When that failed commit was the commit-and-rethrow the
 * rollback rules promised for an exception the method had already thrown, both failures escape as one
 * TransactionSystemException: the commit failure is its `previous`, the method's exception its
 * $applicationException (Spring's shape — the commit exception overrides, the application exception is kept).
 *
 * TIMEOUTS are enforced here, on the OUTERMOST transaction only (a joined REQUIRED or a NESTED savepoint runs
 * under the outer budget — Spring semantics). Right after beginTransaction() the StatementTimeoutApplier tells
 * the driver to give up on a statement that runs past `timeout` seconds (skippable with
 * firefly.data.transaction.statement-timeout=false), and a wall-clock deadline is taken; when the work RETURNS
 * past that deadline the transaction is rolled back and TransactionTimedOutException (504) is thrown — a
 * method whose own exception is what ended it keeps that exception. `#[Transactional(timeout:)]` wins over
 * firefly.data.transaction.default-timeout; 0 on both means no deadline.
 *
 * SYNCHRONIZATIONS: the TransactionSynchronizationRegistry learns which connection the outermost transaction
 * runs on (enter/leave, always paired in the finally) so a #[TransactionalEventListener] queued while this
 * template runs binds to THIS transaction — including one queued DURING the BEFORE_COMMIT drain by a listener
 * that publishes, which the registry folds into the same commit. That drain happens inside
 * Connection::commit() (Laravel's TransactionCommitting event), which is why commit() below rolls back when
 * the commit itself throws.
 */
final class TransactionTemplate
{
    private readonly PersistenceExceptionTranslator $translator;

    private readonly DataSettings $settings;

    private readonly StatementTimeoutApplier $timeouts;

    public function __construct(
        private readonly ?DomainEventDispatcher $dispatcher = null,
        ?PersistenceExceptionTranslator $translator = null,
        ?DataSettings $settings = null,
        private readonly ?TransactionSynchronizationRegistry $synchronizations = null,
    ) {
        $this->translator = $translator ?? new PersistenceExceptionTranslator;
        $this->settings = $settings ?? new DataSettings;
        $this->timeouts = new StatementTimeoutApplier;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function execute(Closure $work, ?TransactionalDescriptor $descriptor = null): mixed
    {
        $d = $descriptor ?? new TransactionalDescriptor;
        $connection = DB::connection($d->connection);
        $active = $connection->transactionLevel() > 0;

        try {
            return match ($d->propagation) {
                Propagation::MANDATORY => $active ? $work() : throw new TransactionRequiredException,
                Propagation::NEVER => $active ? throw new TransactionNotAllowedException : $work(),
                Propagation::SUPPORTS, Propagation::NOT_SUPPORTED => $work(),
                Propagation::REQUIRED => $active ? $work() : $this->runInTransaction($connection, $work, $d, true),
                Propagation::REQUIRES_NEW, Propagation::NESTED => $this->runInTransaction($connection, $work, $d, ! $active),
            };
        } catch (Throwable $e) {
            throw $this->translator->translate($e, $connection->getDriverName());
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function runInTransaction(Connection $connection, Closure $work, TransactionalDescriptor $d, bool $outermost): mixed
    {
        if ($outermost) {
            $this->applySessionSettings($connection, $d);
        }

        $connection->beginTransaction();

        if ($outermost) {
            // Every connection the manager builds carries its name (ConnectionFactory writes config['name']); one
            // that does not can only be re-resolved as the default, which is what DB::connection(null) returns.
            $this->synchronizations?->enter($connection->getName() ?? DB::getDefaultConnection());
        }

        $timeout = $outermost ? $this->effectiveTimeout($d) : 0;
        $restore = $timeout > 0 && $this->settings->statementTimeout ? $this->timeouts->apply($connection, $timeout) : null;
        // (int): hrtime(true) is an int on every 64-bit build; the cast keeps PHPStan's int|float|false union out.
        $deadline = $timeout > 0 ? (int) hrtime(true) + $timeout * 1_000_000_000 : null;

        try {
            try {
                $result = $work();
            } catch (Throwable $e) {
                $translated = $this->translator->translate($e, $connection->getDriverName());

                if ($outermost) {
                    // Queue after-commit events BEFORE resolving the tx, on THIS descriptor's connection: Laravel fires
                    // them on that connection's commit, discards on rollBack.
                    $this->dispatcher?->dispatchAfterCommit($d->connection);
                }

                if ($this->shouldRollBack($translated, $e, $d)) {
                    $connection->rollBack();
                } else {
                    try {
                        $this->commit($connection);
                    } catch (Throwable $commitFailure) {
                        throw new TransactionSystemException($translated, $this->translator->translate($commitFailure, $connection->getDriverName()));
                    }
                }

                throw $translated;
            }

            if ($deadline !== null && (int) hrtime(true) > $deadline) {
                // Drain the tracker either way (it must not leak into the next unit of work); the rollback discards
                // the after-commit callbacks it queued.
                $this->dispatcher?->dispatchAfterCommit($d->connection);
                $connection->rollBack();

                throw new TransactionTimedOutException(sprintf(
                    'The transaction ran for longer than its %d second timeout and was rolled back.',
                    $timeout,
                ));
            }

            if ($outermost) {
                $this->dispatcher?->dispatchAfterCommit($d->connection);
            }

            $this->commit($connection);

            return $result;
        } finally {
            if ($outermost) {
                $this->synchronizations?->leave();
            }
            if ($restore !== null) {
                $restore();
            }
        }
    }

    /** The attribute's timeout, else the configured default; never negative. */
    private function effectiveTimeout(TransactionalDescriptor $d): int
    {
        return max(0, $d->timeout ?? $this->settings->defaultTimeout);
    }

    /**
     * Commit, and if the commit itself throws, unwind whatever is still open before letting the failure out — a
     * transaction left open on a pooled connection outlives the request that started it. A deferred constraint
     * is the textbook case: the INSERT succeeds, COMMIT reports the violation, and sqlite/Postgres leave the
     * transaction open (level still 1) for the caller to roll back. The failure leaves raw; execute()'s catch
     * translates it like any other, so the caller sees the DataAccessException family with the driver's
     * exception as `previous`.
     */
    private function commit(Connection $connection): void
    {
        try {
            $connection->commit();
        } catch (Throwable $e) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /** The firefly.data.* settings this template runs under (translation, the default timeout, the statement-timeout switch). */
    public function settings(): DataSettings
    {
        return $this->settings;
    }

    private function shouldRollBack(Throwable $translated, Throwable $original, TransactionalDescriptor $d): bool
    {
        foreach ($d->noRollbackFor as $type) {
            if ($translated instanceof $type || $original instanceof $type) {
                return false; // noRollbackFor wins: commit-and-rethrow
            }
        }

        foreach ($d->rollbackFor as $type) {
            if ($translated instanceof $type || $original instanceof $type) {
                return true;
            }
        }

        return false; // not listed in rollbackFor: commit-and-rethrow
    }

    private function applySessionSettings(Connection $connection, TransactionalDescriptor $d): void
    {
        if ($d->isolation !== Isolation::DEFAULT) {
            try {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL '.$d->isolation->value);
            } catch (Throwable) {
                // Best-effort: the driver may not support session isolation (e.g. SQLite). Documented latent.
            }
        }

        if ($d->readOnly) {
            try {
                $connection->statement('SET TRANSACTION READ ONLY');
            } catch (Throwable) {
                // Best-effort read-only hint where the driver supports it. Documented latent.
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;
use Firefly\Data\DataSettings;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Transaction\Exception\TransactionNotAllowedException;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
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
 * then translated and rethrown — never left open.
 */
final class TransactionTemplate
{
    private readonly PersistenceExceptionTranslator $translator;

    private readonly DataSettings $settings;

    public function __construct(
        private readonly ?DomainEventDispatcher $dispatcher = null,
        ?PersistenceExceptionTranslator $translator = null,
        ?DataSettings $settings = null,
    ) {
        $this->translator = $translator ?? new PersistenceExceptionTranslator;
        $this->settings = $settings ?? new DataSettings;
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
                $this->commit($connection);
            }

            throw $translated;
        }

        if ($outermost) {
            $this->dispatcher?->dispatchAfterCommit($d->connection);
        }

        $this->commit($connection);

        return $result;
    }

    /**
     * Commit, and if the commit itself throws, unwind whatever is still open before letting the failure out — a
     * transaction left open on a pooled connection outlives the request that started it.
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

    /** The firefly.data.* settings this template runs under (the default timeout is enforced from Task 10 on). */
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

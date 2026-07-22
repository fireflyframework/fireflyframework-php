<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;
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
 */
final class TransactionTemplate
{
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

        return match ($d->propagation) {
            Propagation::MANDATORY => $active ? $work() : throw new TransactionRequiredException,
            Propagation::NEVER => $active ? throw new TransactionNotAllowedException : $work(),
            Propagation::SUPPORTS, Propagation::NOT_SUPPORTED => $work(),
            Propagation::REQUIRED => $active ? $work() : $this->runInTransaction($connection, $work, $d, true),
            Propagation::REQUIRES_NEW, Propagation::NESTED => $this->runInTransaction($connection, $work, $d, ! $active),
        };
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
            if ($this->shouldRollBack($e, $d)) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }

            throw $e;
        }

        $connection->commit();

        return $result;
    }

    private function shouldRollBack(Throwable $e, TransactionalDescriptor $d): bool
    {
        foreach ($d->noRollbackFor as $type) {
            if ($e instanceof $type) {
                return false; // noRollbackFor wins: commit-and-rethrow
            }
        }

        foreach ($d->rollbackFor as $type) {
            if ($e instanceof $type) {
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

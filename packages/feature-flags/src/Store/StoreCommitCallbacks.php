<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Throwable;

/**
 * Connection::afterCommit chooses the last transaction in the shared Laravel manager, even on another
 * connection. Track this connection's events instead. Released savepoints inherit their parent's lifetime,
 * like TransactionSynchronizationRegistry's BEFORE_COMMIT queue; audit IDs are never transaction identities.
 * Keep the connection's existing dispatcher and transaction manager intact. As with other Laravel listeners,
 * an earlier application transaction listener that throws can prevent delivery; it is not a flag observer.
 * These subscriptions live with the connection, so construct the store once per application connection.
 */
final class StoreCommitCallbacks
{
    /** @var list<array{int, Closure(): void}> */
    private array $pending = [];

    private ?Throwable $nativeAbort = null;

    public function __construct(private readonly Connection $connection)
    {
        $dispatcher = $connection->getEventDispatcher();
        if ($dispatcher === null) {
            $dispatcher = new Dispatcher;
            $connection->setEventDispatcher($dispatcher);
        }
        $dispatcher->listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            if ($event->connection === $this->connection && $event->connection->transactionLevel() === 1) {
                $this->pending = [];
                $this->nativeAbort = null;
            }
        });
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            if ($event->connection === $this->connection && $this->nativeAbort !== null) {
                $cause = $this->nativeAbort;
                // transaction(callback) decrements its level after a commit exception. End PDO's stale
                // transaction first, while Laravel can still perform a root rollback; never report commit success.
                try {
                    $this->connection->rollBack(0);
                } finally {
                    throw new FlagStoreTransactionAborted($cause);
                }
            }
        });
        $dispatcher->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            if ($event->connection !== $this->connection || $this->nativeAbort !== null) {
                return;
            }
            $level = $event->connection->transactionLevel();
            if ($level > 0) {
                $this->pending = array_map(static fn (array $entry): array => [min($entry[0], $level), $entry[1]], $this->pending);

                return;
            }
            $callbacks = $this->pending;
            $this->pending = [];
            foreach ($callbacks as [, $callback]) {
                $callback();
            }
        });
        $dispatcher->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            if ($event->connection === $this->connection) {
                $level = $event->connection->transactionLevel();
                $this->pending = array_values(array_filter($this->pending, static fn (array $entry): bool => $entry[0] <= $level));
            }
        });
    }

    public function nativeTransactionAborted(Throwable $cause): void
    {
        $this->nativeAbort = $cause;
        $this->pending = [];
    }

    /** @param Closure(): void $callback */
    public function afterCommit(Closure $callback): void
    {
        if ($this->nativeAbort !== null) {
            return;
        }
        $level = $this->connection->transactionLevel();
        if ($level === 0) {
            $callback();
        } else {
            $this->pending[] = [$level, $callback];
        }
    }
}

<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;

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
            }
        });
        $dispatcher->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            if ($event->connection !== $this->connection) {
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

    /** @param Closure(): void $callback */
    public function afterCommit(Closure $callback): void
    {
        $level = $this->connection->transactionLevel();
        if ($level === 0) {
            $callback();
        } else {
            $this->pending[] = [$level, $callback];
        }
    }
}

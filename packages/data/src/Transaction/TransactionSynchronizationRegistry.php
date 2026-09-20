<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;

/**
 * Spring's TransactionSynchronizationRegistry: "run this in phase X of the CURRENT transaction". Deliberately
 * thin, because Laravel already keeps the after-phases per transaction record — AFTER_COMMIT is
 * Connection::afterCommit() (runs after the ROOT commit, discarded on rollback), AFTER_ROLLBACK is
 * Connection::afterRollBack() (runs when the transaction — or savepoint — it was queued in rolls back),
 * AFTER_COMPLETION is both (exactly one of them fires). BEFORE_COMMIT has no Laravel callback, so it is queued
 * here and drained by a TransactionCommitting listener this registry installs ONCE per connection on the
 * connection's own dispatcher: Laravel fires that event at level 1, before the PDO commit, so a callback that
 * throws aborts the commit and TransactionTemplate rolls back. A TransactionRolledBack that reaches level 0
 * discards the queue.
 *
 * Which transaction is "current"? The one TransactionTemplate is running: it calls enter($connectionName) when
 * it opens an OUTERMOST transaction and leave() when that resolves, so a #[Transactional(connection: 'x')]
 * method's listeners bind to x. With no template frame (an event published inside a plain DB::transaction())
 * the default connection is used — and Laravel's own callbacks then behave exactly as they do for any
 * Laravel code.
 *
 * A singleton bean (DataAutoConfiguration) — the queue and the frame stack are per process, which under
 * PHP-FPM is per request and under Octane per worker, where the frame stack is always empty between requests
 * because every template frame is left in a finally.
 */
final class TransactionSynchronizationRegistry
{
    /** @var list<string> connection names of the template transactions currently open, innermost last */
    private array $frames = [];

    /** @var array<string, list<Closure(): void>> connection name => queued BEFORE_COMMIT callbacks */
    private array $beforeCommit = [];

    /** @var array<string, true> connections whose dispatcher already carries the two listeners */
    private array $subscribed = [];

    public function enter(string $connection): void
    {
        $this->frames[] = $connection;
    }

    public function leave(): void
    {
        array_pop($this->frames);
    }

    public function currentConnection(): ?string
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];
    }

    public function isTransactionActive(): bool
    {
        return $this->connection()->transactionLevel() > 0;
    }

    /**
     * @param  Closure(): void  $callback
     */
    public function register(TransactionPhase $phase, Closure $callback): void
    {
        $connection = $this->connection();

        switch ($phase) {
            case TransactionPhase::AFTER_COMMIT:
                $connection->afterCommit($callback);
                break;
            case TransactionPhase::AFTER_ROLLBACK:
                $connection->afterRollBack($callback);
                break;
            case TransactionPhase::AFTER_COMPLETION:
                $connection->afterCommit($callback);
                $connection->afterRollBack($callback);
                break;
            case TransactionPhase::BEFORE_COMMIT:
                $this->queueBeforeCommit($connection, $callback);
                break;
        }
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function queueBeforeCommit(Connection $connection, Closure $callback): void
    {
        $name = $connection->getName();
        $this->beforeCommit[$name][] = $callback;

        if (isset($this->subscribed[$name])) {
            return;
        }

        $dispatcher = $connection->getEventDispatcher();
        if ($dispatcher === null) {
            throw new ConfigurationException("A BEFORE_COMMIT listener needs the [{$name}] connection's event dispatcher, and it has none.");
        }

        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($name): void {
            if ($event->connectionName === $name) {
                $this->runBeforeCommit($name);
            }
        });
        $dispatcher->listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use ($name): void {
            if ($event->connectionName === $name && $event->connection->transactionLevel() === 0) {
                $this->beforeCommit[$name] = [];
            }
        });

        $this->subscribed[$name] = true;
    }

    /** Take the queue, clear it, run it — so a callback that throws cannot leave stale callbacks behind. */
    private function runBeforeCommit(string $name): void
    {
        $callbacks = $this->beforeCommit[$name] ?? [];
        $this->beforeCommit[$name] = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    private function connection(): Connection
    {
        return DB::connection($this->currentConnection());
    }
}

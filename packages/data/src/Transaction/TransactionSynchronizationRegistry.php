<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
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
 * throws aborts the commit and TransactionTemplate rolls back.
 *
 * SAVEPOINTS: a BEFORE_COMMIT queued inside a savepoint (Propagation::NESTED, or REQUIRES_NEW joined on the same
 * connection) belongs to that savepoint exactly as Laravel's own after-callbacks do, so the four phases agree
 * when the savepoint rolls back and the outer transaction goes on to commit: the event's listeners see
 * AFTER_ROLLBACK (and AFTER_COMPLETION) and nothing else — never a BEFORE_COMMIT run against a state where its
 * event's changes are gone. Each entry is tagged with the transaction level it was queued at, and the two
 * after-listeners apply DatabaseTransactionsManager's own rules to the tag: a TransactionRolledBack drops the
 * entries queued deeper than the level it unwinds to (level 0 is the full sweep), and a TransactionCommitted
 * that RELEASES a savepoint (level still > 0) folds the entries queued inside it down to the enclosing level,
 * so they live or die with the enclosing transaction from then on — a sibling savepoint rolling back later
 * leaves them alone, the enclosing one rolling back takes them with it. A TransactionCommitted that reaches
 * level 0 sweeps whatever is left, so nothing can ever outlive the transaction it was queued in.
 *
 * The drain is RE-ENTRANT: because TransactionCommitting fires while the level is still 1, a BEFORE_COMMIT
 * callback that publishes an event with its own BEFORE_COMMIT listener finds the transaction active and
 * registers again mid-drain. Those late registrations belong to THIS commit — the queue is taken and run
 * until it stays empty, so they fire before the PDO commit (and can veto it) rather than lying in wait for
 * whatever transaction happens to commit next on that connection. (Spring's beforeCommit loop walks a snapshot
 * and simply never calls a synchronization registered during it; running them is the more useful reading of
 * "the current transaction", and, as with any listener that re-publishes its own event, a chain that never
 * stops registering is the caller's bug.)
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

    /**
     * Connection name => queued BEFORE_COMMIT callbacks, each tagged with the transaction level it was queued at
     * (folded down as savepoints release — see the class docblock).
     *
     * @var array<string, list<array{int, Closure(): void}>>
     */
    private array $beforeCommit = [];

    /** @var array<string, true> connections whose dispatcher already carries the three listeners */
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
        $this->beforeCommit[$name][] = [$connection->transactionLevel(), $callback];

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
        // Both after-events fire once Connection has already lowered its level, so transactionLevel() is the
        // level the commit or rollback unwound TO — the same number DatabaseTransactionsManager gets.
        $dispatcher->listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($name): void {
            if ($event->connectionName === $name) {
                $this->release($name, $event->connection->transactionLevel());
            }
        });
        $dispatcher->listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use ($name): void {
            if ($event->connectionName === $name) {
                $this->discard($name, $event->connection->transactionLevel());
            }
        });

        $this->subscribed[$name] = true;
    }

    /**
     * Take the queue, clear it, run it — and go again while a callback has queued more, because the level is
     * still 1 here and a listener that publishes is a registration in disguise (see the class docblock). Taking
     * the batch BEFORE running it means a callback that throws cannot leave its batch behind; whatever it
     * queued before throwing is swept by the rollback TransactionTemplate performs on a failed commit.
     */
    private function runBeforeCommit(string $name): void
    {
        while (($entries = $this->beforeCommit[$name] ?? []) !== []) {
            $this->beforeCommit[$name] = [];

            foreach ($entries as [, $callback]) {
                $callback();
            }
        }
    }

    /**
     * A commit unwound to $level. At 0 the root committed and the drain already ran everything, so the queue is
     * swept; above 0 a savepoint was RELEASED, and what was queued inside it now belongs to the enclosing
     * transaction — DatabaseTransactionsManager::stageTransactions() re-parents its records the same way.
     */
    private function release(string $name, int $level): void
    {
        if ($level === 0) {
            $this->beforeCommit[$name] = [];

            return;
        }

        $this->beforeCommit[$name] = array_map(
            static fn (array $entry): array => $entry[0] > $level ? [$level, $entry[1]] : $entry,
            $this->beforeCommit[$name] ?? [],
        );
    }

    /**
     * A rollback unwound to $level: everything queued deeper is gone with the savepoint (or, at 0, with the
     * transaction) — DatabaseTransactionsManager::rollback() rejects its pending records by the same comparison.
     */
    private function discard(string $name, int $level): void
    {
        $this->beforeCommit[$name] = array_values(array_filter(
            $this->beforeCommit[$name] ?? [],
            static fn (array $entry): bool => $entry[0] <= $level,
        ));
    }

    private function connection(): Connection
    {
        return DB::connection($this->currentConnection());
    }
}

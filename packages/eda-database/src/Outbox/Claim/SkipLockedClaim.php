<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Illuminate\Database\Connection;

/**
 * PostgreSQL, MySQL 8.0.1+ and MariaDB 10.6+: pick the oldest claimable ids with `FOR UPDATE SKIP LOCKED` and lease
 * them in the same short transaction, so concurrent claimants walk past each other's rows instead of queueing on
 * them. On MySQL and MariaDB the transaction runs READ COMMITTED (when no transaction is already open — the isolation
 * of an open one cannot change): under InnoDB's default REPEATABLE READ the claim takes gap locks, and a publisher
 * inserting a new outbox row then waits on — or deadlocks with — the claim; PyFly measured exactly that.
 */
final class SkipLockedClaim implements OutboxClaimStrategy
{
    public const string NAME = 'skip-locked';

    public function name(): string
    {
        return self::NAME;
    }

    public function claim(Connection $connection, OutboxClaim $claim): array
    {
        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('set transaction isolation level read committed');
        }

        /** @var list<int> $ids */
        $ids = $connection->transaction(static function () use ($connection, $claim): array {
            $ids = ClaimableRows::ids(ClaimableRows::candidates($connection, $claim)->lock('for update skip locked'));
            ClaimableRows::lease($connection, $ids, $claim);

            return $ids;
        });

        return $ids;
    }
}

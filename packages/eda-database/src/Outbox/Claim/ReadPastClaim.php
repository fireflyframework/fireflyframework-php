<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Illuminate\Database\Connection;

/**
 * SQL Server: the same shape as SkipLockedClaim with the table hint SQL Server has instead of SKIP LOCKED —
 * `WITH (UPDLOCK, READPAST, ROWLOCK)` takes update locks on the rows it reads and skips rows another transaction has
 * locked. The hint goes through Builder::lock(), which SqlServerGrammar places right after the table name.
 */
final class ReadPastClaim implements OutboxClaimStrategy
{
    public const string NAME = 'read-past';

    public function name(): string
    {
        return self::NAME;
    }

    public function claim(Connection $connection, OutboxClaim $claim): array
    {
        /** @var list<int> $ids */
        $ids = $connection->transaction(static function () use ($connection, $claim): array {
            $ids = ClaimableRows::ids(ClaimableRows::candidates($connection, $claim)->lock('with (updlock, readpast, rowlock)'));
            ClaimableRows::lease($connection, $ids, $claim);

            return $ids;
        });

        return $ids;
    }
}

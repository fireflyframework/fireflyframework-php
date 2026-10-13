<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Illuminate\Database\Connection;

/**
 * The portable claim: read candidate ids with no lock at all, then lease each with one conditional UPDATE that
 * repeats the claimable predicate — `WHERE id = ? AND status = 'PENDING' AND (claimed_until IS NULL OR claimed_until
 * < :now)`. A row is won when that UPDATE changed exactly one row. It is safe on every driver because every driver
 * evaluates an UPDATE's WHERE against the row's current committed state while holding the row (or, on SQLite, the
 * database) for writing, so of two claimants racing for a row exactly one sees it still claimable. The default for
 * SQLite (one writer at a time) and for any server whose locking SQL the capability probe does not know.
 */
final class OptimisticLeaseClaim implements OutboxClaimStrategy
{
    public const string NAME = 'optimistic-lease';

    public function name(): string
    {
        return self::NAME;
    }

    public function claim(Connection $connection, OutboxClaim $claim): array
    {
        $won = [];

        foreach (ClaimableRows::ids(ClaimableRows::candidates($connection, $claim)) as $id) {
            $changed = ClaimableRows::query($connection, $claim)->where('id', $id)->update(ClaimableRows::leaseColumns($claim));

            if ($changed === 1) {
                $won[] = $id;
            }
        }

        return $won;
    }
}

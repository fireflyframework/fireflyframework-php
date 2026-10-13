<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Illuminate\Database\Connection;

/**
 * How a consumer or a relay takes rows out of the outbox. Every strategy ends the same way — the rows it returns carry
 * `claimed_by = $claim->claimant` and `claimed_until = $claim->until`, committed before the caller does any work with
 * them — and differs only in how it picks the rows without two claimants picking the same one:
 *
 *   - SkipLockedClaim      `FOR UPDATE SKIP LOCKED` inside a short transaction (PostgreSQL, MySQL 8.0.1+, MariaDB 10.6+);
 *   - ReadPastClaim        `WITH (UPDLOCK, READPAST, ROWLOCK)` inside a short transaction (SQL Server);
 *   - OptimisticLeaseClaim an unlocked read, then one conditional UPDATE per row; the row is won when exactly one row
 *                          changed (any driver — the portable default for SQLite and anything unknown).
 *
 * The LEASE is the guarantee, not the row lock: a lock dies with the claim transaction, so it cannot protect a
 * handler that runs after it. A row is claimable while it is PENDING and its lease is empty or over; a claimant that
 * crashes leaves its rows to the next claimant once their lease ends.
 */
interface OutboxClaimStrategy
{
    public function name(): string;

    /**
     * Lease up to $claim->limit claimable rows to $claim->claimant, in a unit of work of its own.
     *
     * @return list<int> the ids won, ascending
     */
    public function claim(Connection $connection, OutboxClaim $claim): array;
}

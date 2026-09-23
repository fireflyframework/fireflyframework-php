<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\TransactionalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Resilience\Method\Retry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The combination the whole advice-order argument is ABOUT: `#[Retry]` at order 200 beside `#[Transactional]`
 * at 1000, on one method, writing real rows. Nothing else in the suite carries both — which is how a retry
 * that re-entered the chain one link too far shipped with a hundred and twenty-two green resilience tests.
 *
 * Each method inserts BEFORE it decides whether to fail, so the row an attempt wrote is a fact about that
 * attempt: if the attempt is inside a transaction of its own, the row disappears when the attempt throws,
 * and if it is not, the insert auto-commits and stays. Counting rows afterwards therefore reports how many
 * attempts ran untransacted, which is the only thing worth asserting here.
 *
 * `$attempts` is a plain property precisely because PHP state is NOT transactional: a rollback takes the row
 * back and leaves the counter, so the fixture can fail a fixed number of times and the assertion still reads
 * the rows alone. NOT final — the proxy extends it.
 */
#[Service]
class LedgerService
{
    public int $attempts = 0;

    /** Fails every attempt, so the retry gives up and the caller sees the cause. */
    #[Retry('ledger')]
    #[Transactional]
    public function postAlwaysFailing(string $ref): void
    {
        DB::table('ledger')->insert(['ref' => $ref.':'.(++$this->attempts)]);

        throw new RuntimeException('ledger down');
    }

    /** Fails twice, then commits — so exactly ONE row may survive, the last attempt's. */
    #[Retry('ledger')]
    #[Transactional]
    public function postSucceedingOnTheThirdAttempt(string $ref): string
    {
        DB::table('ledger')->insert(['ref' => $ref.':'.(++$this->attempts)]);

        if ($this->attempts < 3) {
            throw new RuntimeException('ledger down');
        }

        return $ref;
    }
}

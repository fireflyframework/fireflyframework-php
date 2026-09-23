<?php

declare(strict_types=1);

use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Resilience\Method\ResilienceAdviceSource;
use Firefly\Resilience\Tests\Fixtures\TransactionalMethod\LedgerService;
use Firefly\Resilience\Tests\Support\ResilienceTransactionalCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(ResilienceTransactionalCapstoneTestCase::class);

/*
 | "A retry opens a new transaction per attempt" is the sentence ResilienceMethodInterceptor's docblock,
 | ResilienceAdviceSource's docblock and skeleton/config/firefly.php all print. It is a claim about rows, and
 | this is where rows are counted. Every assertion below is about what is left in `ledger` after the retry
 | finished: the fixture inserts before it decides whether to fail, so a row that survives is an attempt that
 | ran with no transaction around it.
 */

it('chains the resilience advice OUTSIDE the transaction on a method carrying both attributes', function () {
    /** @var ResilienceTransactionalCapstoneTestCase $this */
    $context = $this->fireflyContext();

    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);
    $advice = $plan->adviceFor(LedgerService::class);

    // The order that makes the rest of this file meaningful: resilience (200) then the transaction (1000),
    // so the retry link wraps the transactional one and not the other way round.
    expect(array_keys($advice))->toBe([ResilienceAdviceSource::ID, Advice::TRANSACTIONAL])
        ->and($advice[ResilienceAdviceSource::ID]->order)->toBe(200)
        ->and($advice[ResilienceAdviceSource::ID]->order)->toBeLessThan($advice[Advice::TRANSACTIONAL]->order)
        ->and(array_column($plan->methodsFor(LedgerService::class)['postAlwaysFailing'], 'advice'))
        ->toBe([ResilienceAdviceSource::ID, Advice::TRANSACTIONAL]);
});

it('rolls every attempt back when the retry gives up, leaving nothing behind', function () {
    /** @var ResilienceTransactionalCapstoneTestCase $this */
    /** @var LedgerService $service */
    $service = $this->app()->make(LedgerService::class);

    expect(fn () => $service->postAlwaysFailing('ref-1'))->toThrow(RuntimeException::class);

    // Three attempts ran — the retry budget was spent, not abandoned after the first...
    expect($service->attempts)->toBe(3)
        // ...and every one of them was inside a transaction of its own, so the table is EMPTY. Without a
        // per-attempt transaction, attempts 2 and 3 auto-commit and two orphan rows are left behind with
        // nothing that could ever roll them back — the silent half-written write this whole ordering exists
        // to prevent.
        ->and(DB::table('ledger')->count())->toBe(0);
});

it('commits only the attempt that succeeded, never the ones that failed on the way', function () {
    /** @var ResilienceTransactionalCapstoneTestCase $this */
    /** @var LedgerService $service */
    $service = $this->app()->make(LedgerService::class);

    expect($service->postSucceedingOnTheThirdAttempt('ref-2'))->toBe('ref-2')
        ->and($service->attempts)->toBe(3)
        // ONE row, the third attempt's. Attempts 1 and 2 each inserted and threw; their rows are gone
        // because each ran in its own transaction. Attempts that run untransacted leave ['ref-2:2',
        // 'ref-2:3'] here instead.
        ->and(DB::table('ledger')->pluck('ref')->all())->toBe(['ref-2:3']);
});

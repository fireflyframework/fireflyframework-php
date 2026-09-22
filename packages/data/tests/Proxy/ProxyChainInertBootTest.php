<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Data\Tests\Fixtures\Chain\ChainedLedger;
use Firefly\Data\Tests\Fixtures\Chain\RecordingTransactionInterceptor;
use Firefly\Data\Tests\Support\ChainInertBootTestCase;
use Firefly\Data\Transaction\TransactionInterceptor;

uses(ChainInertBootTestCase::class);

/*
 | The "annotations are inert until the capability is enabled" path, through the real pipeline: the plan still
 | names the audit advice (a compiled plan does not change with configuration), the proxy is still generated with
 | the audit link, but its interceptor bean is absent — so, because the advice opted in, the registry hands the
 | proxy a PassThroughInterceptor and every call proceeds straight to the next link.
 */
it('wraps the bean with a pass-through in place of an inert advice whose interceptor is unbound, and still runs the transaction', function () {
    /** @var ChainInertBootTestCase $this */
    $context = $this->fireflyContext();
    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);

    expect($this->app()->bound(AuditInterceptor::class))->toBeFalse()
        ->and(array_keys($plan->adviceFor(ChainedLedger::class)))->toBe(['audit', 'tx']);

    /** @var ChainedLedger $ledger */
    $ledger = $context->get(ChainedLedger::class);
    /** @var RecordingTransactionInterceptor $tx */
    $tx = $context->get(TransactionInterceptor::class);

    expect($ledger::class)->toBe(ChainedLedger::class.ProxyPlan::PROXY_SUFFIX)
        ->and($ledger->post('rent'))->toBe('posted:rent')
        ->and($ledger->entries)->toBe(['rent/none'])
        ->and($tx->calls)->toBe(1)
        ->and($tx->levels)->toBe([1])
        ->and($ledger->peek())->toBe('peek:1')
        ->and($this->audit->log)->toBe([]); // nothing audited: the unbound instance never saw a call
});

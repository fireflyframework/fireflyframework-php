<?php

declare(strict_types=1);

use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Data\Tests\Fixtures\Chain\ChainedLedger;
use Firefly\Data\Tests\Fixtures\Chain\RecordingTransactionInterceptor;
use Firefly\Data\Tests\Support\ChainBootTestCase;
use Firefly\Data\Transaction\TransactionInterceptor;

uses(ChainBootTestCase::class);

it('plans both advice kinds from the scanned AdviceSource beans, outer advice first', function () {
    /** @var ChainBootTestCase $this */
    /** @var ProxyPlan $plan */
    $plan = $this->fireflyContext()->get(ProxyPlan::class);

    expect($plan)->toBeInstanceOf(ProxyPlan::class)
        ->and($plan->hasProxyFor(ChainedLedger::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(ChainedLedger::class)))->toBe(['audit', Advice::TRANSACTIONAL])
        ->and(array_column($plan->methodsFor(ChainedLedger::class)['post'], 'advice'))->toBe(['audit', Advice::TRANSACTIONAL])
        ->and(array_column($plan->methodsFor(ChainedLedger::class)['peek'], 'advice'))->toBe(['audit']);
});

it('hands out the generated two-advice proxy for the #[Service] and runs audit around a real transaction', function () {
    /** @var ChainBootTestCase $this */
    $context = $this->fireflyContext();

    /** @var ChainedLedger $ledger */
    $ledger = $context->get(ChainedLedger::class);
    /** @var TransactionInterceptor $tx */
    $tx = $context->get(TransactionInterceptor::class);

    // The materialised proxy, not the bare class; the tx link is the fixture configuration's bean, proving the
    // post-processor injects the container's TransactionInterceptor rather than one of its own.
    expect($ledger::class)->toBe(ChainedLedger::class.ProxyPlan::PROXY_SUFFIX)
        ->and($ledger)->toBeInstanceOf(ChainedLedger::class)
        ->and($tx)->toBeInstanceOf(RecordingTransactionInterceptor::class);

    /** @var RecordingTransactionInterceptor $tx */
    expect($ledger->post('rent'))->toBe('posted:rent')
        ->and($ledger->entries)->toBe(['rent/none'])
        ->and($tx->calls)->toBe(1)
        ->and($tx->levels)->toBe([1]) // the method body ran inside the transaction the tx link opened
        ->and($ledger->peek())->toBe('peek:1')
        ->and($tx->calls)->toBe(1) // peek() carries no transactional advice
        ->and($this->audit->log)->toBe(['before:post:posting', 'after:post', 'before:peek:peeking', 'after:peek']);

    $ledger->wipe();

    expect($ledger->entries)->toBe([])
        ->and($tx->calls)->toBe(2)
        ->and($this->audit->log)->toHaveCount(6);
});

it('resolves the audit link through the InterceptorRegistry to the very instance the app bound', function () {
    /** @var ChainBootTestCase $this */
    $context = $this->fireflyContext();

    /** @var ChainedLedger $ledger */
    $ledger = $context->get(ChainedLedger::class);
    $ledger->peek();

    // The log landed on $this->audit, the instance bound before boot: the registry resolved the advice's
    // interceptor class from the container, not a fresh AuditInterceptor of its own.
    /** @var AuditInterceptor $bound */
    $bound = $context->get(AuditInterceptor::class);
    expect($bound)->toBe($this->audit)
        ->and($this->audit->log)->toBe(['before:peek:peeking', 'after:peek']);
});

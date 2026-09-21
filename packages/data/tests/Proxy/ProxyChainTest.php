<?php

declare(strict_types=1);

use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Proxy\ProxyPlanner;
use Firefly\Data\Proxy\TransactionalAdviceSource;
use Firefly\Data\Tests\Fixtures\Chain\AuditAdviceSource;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Data\Tests\Fixtures\Chain\ChainedLedger;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/** @return array<string,string> */
function chainPsr4(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\Chain\\' => __DIR__.'/../Fixtures/Chain'];
}

function chainPlanner(): ProxyPlanner
{
    return new ProxyPlanner([new TransactionalAdviceSource, new AuditAdviceSource]);
}

/** @return array<string, ProxyMethod> */
function chainedLedgerMethods(): array
{
    $planner = chainPlanner();

    return $planner->proxyMethods($planner->plan(chainPsr4()))[ChainedLedger::class];
}

/** @return TransactionInterceptor&object{calls: int} */
function countingTxInterceptor(): TransactionInterceptor
{
    return new class extends TransactionInterceptor
    {
        public int $calls = 0;

        public function __construct()
        {
            parent::__construct(new TransactionTemplate);
        }

        public function run(Closure $proceed, TransactionalDescriptor $descriptor): mixed
        {
            $this->calls++;

            return $proceed();
        }
    };
}

it('merges two advice sources into one plan, outer advice first', function () {
    $plan = chainPlanner()->plan(chainPsr4());

    expect($plan->hasProxyFor(ChainedLedger::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(ChainedLedger::class)))->toBe(['audit', Advice::TRANSACTIONAL])
        ->and(array_column($plan->methodsFor(ChainedLedger::class)['post'], 'advice'))->toBe(['audit', Advice::TRANSACTIONAL])
        ->and(array_column($plan->methodsFor(ChainedLedger::class)['peek'], 'advice'))->toBe(['audit']);
});

it('generates ONE proxy whose source carries a property and a factory per advice kind, and the advice table', function () {
    $source = (new ProxyClassGenerator)->generate(ChainedLedger::class, chainedLedgerMethods());

    expect($source)->toContain('__fireflyAuditInterceptor')
        ->toContain('__fireflyAuditDescriptor')
        ->toContain('__fireflyTxInterceptor')
        ->toContain('__fireflyTxDescriptor')
        ->toContain('public static function __fireflyAdvice(): array')
        ->toContain("'audit' => \\".AuditInterceptor::class.'::class')
        ->toContain("'tx' => \\".TransactionInterceptor::class.'::class')
        ->toContain('Propagation::REQUIRED')
        ->toContain("'label' => 'peeking'");
});

it('runs the audit advice around the transactional one, and audit alone on the plain method', function () {
    $proxyClass = (new ProxyClassGenerator)->load(ChainedLedger::class, chainedLedgerMethods());
    $audit = new AuditInterceptor;
    $tx = countingTxInterceptor();

    /** @var ChainedLedger $proxy */
    $proxy = (new ProxyFactory)->wrap(new ChainedLedger, ChainedLedger::class, $proxyClass, $tx, ['audit' => $audit]);

    expect($proxy)->toBeInstanceOf(ChainedLedger::class)
        ->and((new ReflectionMethod($proxyClass, '__fireflyAdvice'))->invoke(null))->toBe(['audit' => AuditInterceptor::class, 'tx' => TransactionInterceptor::class])
        ->and($proxy->post('rent'))->toBe('posted:rent')
        ->and($proxy->entries)->toBe(['rent/none'])
        ->and($tx->calls)->toBe(1)
        ->and($proxy->peek())->toBe('peek:1')
        ->and($tx->calls)->toBe(1) // peek() carries no transactional advice
        ->and($audit->log)->toBe(['before:post:posting', 'after:post', 'before:peek:peeking', 'after:peek']);

    $proxy->wipe();

    expect($proxy->entries)->toBe([])
        ->and($tx->calls)->toBe(2);
});

it('lets the outer advice rewrite the arguments the real method receives', function () {
    $proxyClass = (new ProxyClassGenerator)->load(ChainedLedger::class, chainedLedgerMethods());

    /** @var ChainedLedger $proxy */
    $proxy = (new ProxyFactory)->wrap(new ChainedLedger, ChainedLedger::class, $proxyClass, countingTxInterceptor(), ['audit' => new AuditInterceptor('rewritten')]);

    expect($proxy->post('original', 'kept'))->toBe('posted:rewritten')
        ->and($proxy->entries)->toBe(['rewritten/kept']);
});

it('refuses to wrap when an advice the proxy runs has no interceptor', function () {
    $proxyClass = (new ProxyClassGenerator)->load(ChainedLedger::class, chainedLedgerMethods());

    expect(fn () => (new ProxyFactory)->wrap(new ChainedLedger, ChainedLedger::class, $proxyClass, countingTxInterceptor()))
        ->toThrow(ConfigurationException::class);
});

<?php

declare(strict_types=1);

use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\InterceptorRegistry;
use Firefly\Data\Proxy\PassThroughInterceptor;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Proxy\ProxyPlanner;
use Firefly\Data\Proxy\TransactionalAdviceSource;
use Firefly\Data\Tests\Fixtures\Chain\AuditAdviceSource;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Data\Tests\Fixtures\Chain\AuditNote;
use Firefly\Data\Tests\Fixtures\Chain\ChainedLedger;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Container\Container;

function auditAdvice(bool $inertWhenUnbound): Advice
{
    return new Advice('audit', AuditInterceptor::class, AuditNote::class, 100, $inertWhenUnbound);
}

/**
 * The same two-advice ChainedLedger proxy ProxyChainTest generates (same sources, same plan, same class).
 *
 * @return array<string, ProxyMethod>
 */
function registryLedgerMethods(): array
{
    $planner = new ProxyPlanner([new TransactionalAdviceSource, new AuditAdviceSource]);

    return $planner->proxyMethods($planner->plan(['Firefly\\Data\\Tests\\Fixtures\\Chain\\' => __DIR__.'/../Fixtures/Chain']))[ChainedLedger::class];
}

/** @return TransactionInterceptor&object{calls: int} */
function registryCountingTx(): TransactionInterceptor
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

it('returns the bound interceptor bean itself', function () {
    $container = new Container;
    $audit = new AuditInterceptor;
    $container->instance(AuditInterceptor::class, $audit);

    expect((new InterceptorRegistry($container))->for(auditAdvice(false)))->toBe($audit)
        ->and((new InterceptorRegistry($container))->for(auditAdvice(true)))->toBe($audit);
});

it('fails loud on an unbound interceptor unless the advice declared itself inert when unbound', function () {
    $registry = new InterceptorRegistry(new Container);

    expect(fn () => $registry->for(auditAdvice(false)))
        ->toThrow(ConfigurationException::class, 'The [audit] advice names '.AuditInterceptor::class.' as its interceptor, but no such bean is bound.');
});

it('degrades an inert advice whose interceptor is unbound to a pass-through that still reaches the next link', function () {
    $registry = new InterceptorRegistry(new Container);

    $link = $registry->for(auditAdvice(true));
    expect($link)->toBeInstanceOf(PassThroughInterceptor::class);

    // Wrap the real chain fixture with the pass-through in the audit slot: post() still runs its transactional
    // link exactly once and reaches the real method, so an inert advice costs nothing but a proceed().
    $proxyClass = (new ProxyClassGenerator)->load(ChainedLedger::class, registryLedgerMethods());
    $tx = registryCountingTx();

    /** @var ChainedLedger $proxy */
    $proxy = (new ProxyFactory)->wrap(new ChainedLedger, ChainedLedger::class, $proxyClass, $tx, ['audit' => $link]);

    expect($proxy->post('rent'))->toBe('posted:rent')
        ->and($proxy->entries)->toBe(['rent/none'])
        ->and($tx->calls)->toBe(1)
        ->and($proxy->peek())->toBe('peek:1')
        ->and($tx->calls)->toBe(1);
});

it('fails loud on a bound bean that is not a MethodInterceptor, inert or not', function () {
    $container = new Container;
    $container->instance(AuditInterceptor::class, new stdClass);
    $registry = new InterceptorRegistry($container);

    expect(fn () => $registry->for(auditAdvice(false)))
        ->toThrow(ConfigurationException::class, 'does not implement Firefly\Data\Proxy\MethodInterceptor')
        ->and(fn () => $registry->for(auditAdvice(true)))
        ->toThrow(ConfigurationException::class, 'does not implement Firefly\Data\Proxy\MethodInterceptor');
});

it('carries the inert flag through the compiled row and reads a row without it as fail-loud', function () {
    $inert = auditAdvice(true);

    expect($inert->toArray()['inert'])->toBeTrue()
        ->and(Advice::fromArray($inert->toArray()))->toEqual($inert)
        ->and(Advice::fromArray(['id' => 'audit', 'interceptor' => AuditInterceptor::class, 'descriptor' => AuditNote::class, 'order' => 100])->inertWhenUnbound)->toBeFalse()
        ->and(Advice::transactional()->inertWhenUnbound)->toBeFalse();
});

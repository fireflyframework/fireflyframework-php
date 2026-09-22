<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Proxy\TxLedger;
use Firefly\Data\Tests\Fixtures\Proxy\TxWidget;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Container\Container;

uses(DatabaseTestCase::class);

/**
 * Pins the exact behaviours the whole #[Transactional] design rests on. If any assertion is wrong the proxy
 * silently corrupts bean state — this is the tripwire. A real DB connection (DatabaseTestCase) is needed because
 * (c) drives the transactional override through the real interceptor.
 */
function wrappedTxWidget(TxWidget $bean): TxWidget
{
    $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Proxy\\' => dirname(__DIR__).'/Fixtures/Proxy'];
    $methods = (new TransactionalScanner)->scanProxyMethods($psr4)[TxWidget::class];
    $proxyClass = (new ProxyClassGenerator)->load(TxWidget::class, $methods);
    $interceptor = new TransactionInterceptor(new TransactionTemplate);

    /** @var TxWidget $proxy */
    $proxy = (new ProxyFactory)->wrap($bean, TxWidget::class, $proxyClass, $interceptor);

    return $proxy;
}

it('(b) produces a proxy that IS-A the target', function () {
    $bean = new TxWidget('cfg');

    expect(wrappedTxWidget($bean))->toBeInstanceOf(TxWidget::class);
});

it('(a) reproduces private + readonly state, including a post-construct-style field', function () {
    $bean = new TxWidget('config-value');
    $bean->boot(); // sets the private counter to 1, standing in for #[PostConstruct]

    $proxy = wrappedTxWidget($bean);

    expect($proxy->counter())->toBe(1)   // private int copied
        ->and($proxy->config())->toBe('config-value'); // readonly promoted ctor property copied
});

it('(c) overridden methods call parent:: on the COPIED state (a counter at 1 becomes 2, not reset)', function () {
    $bean = new TxWidget('cfg');
    $bean->boot(); // counter = 1 BEFORE wrap

    $proxy = wrappedTxWidget($bean);

    // The transactional override runs parent::increment() inside a real tx against the COPIED counter.
    expect($proxy->increment())->toBe(2)
        ->and($proxy->counter())->toBe(2);
});

it('(d) is resolvable by $container->call([$proxy, method]) (the M4 lifecycle contract)', function () {
    $proxy = wrappedTxWidget(new TxWidget('cfg'));

    // $container->call reflects a ReflectionMethod on the object — it can only see inherited methods, never
    // __call() magic. A compose-and-__call proxy would fail here; an IS-A proxy resolves. A real
    // Illuminate\Container\Container is used (mirrors M4's KernelContractTest), exercising the same code path.
    expect((new Container)->call([$proxy, 'config']))->toBe('cfg');
});

it('(e) runs a non-transactional inherited method on the proxy copied state', function () {
    $bean = new TxWidget('cfg');
    $bean->boot(); // counter = 1

    $proxy = wrappedTxWidget($bean);
    $proxy->bump(); // inherited, non-transactional

    expect($proxy->counter())->toBe(2);
});

/**
 * (f) is the tripwire for the hierarchy copy. A closure bound to the DECLARED class alone cannot see a parent's
 * private, and on the PHP 8.3 floor cannot initialise a parent's `protected readonly` either — so a proxied
 * EloquentRepository subclass lost its translator (and, on 8.3, could not be wrapped at all). Every slot is now
 * written from the class that declares it, and two privates under one name stay two slots.
 */
it('(f) reproduces state declared PRIVATE or readonly on a PARENT of the declared class, slot by slot', function () {
    $bean = new TxLedger('m', 's');
    $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Proxy\\' => dirname(__DIR__).'/Fixtures/Proxy'];
    $methods = (new TransactionalScanner)->scanProxyMethods($psr4)[TxLedger::class];
    $proxyClass = (new ProxyClassGenerator)->load(TxLedger::class, $methods);

    /** @var TxLedger $proxy */
    $proxy = (new ProxyFactory)->wrap($bean, TxLedger::class, $proxyClass, new TransactionInterceptor(new TransactionTemplate));

    expect($proxy->secret())->toBe('s')          // parent-private, readonly
        ->and($proxy->manifest())->toBe('m')     // parent protected readonly (promoted)
        ->and($proxy->baseLabel())->toBe('base') // the parent's private $label ...
        ->and($proxy->childLabel())->toBe('child') // ... and the child's, same name, distinct slot
        ->and($proxy->reveal())->toBe('s/m/base/child'); // through the transactional override
});

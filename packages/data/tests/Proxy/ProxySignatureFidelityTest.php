<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Proxy\SignatureBag;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

/**
 * @return array<string, ProxyMethod>
 */
function signatureBagMethods(): array
{
    $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Proxy\\' => __DIR__.'/../Fixtures/Proxy'];

    return (new TransactionalScanner)->scanProxyMethods($psr4)[SignatureBag::class];
}

it('copies typed scalar params with their defaults verbatim', function () {
    $proxyClass = (new ProxyClassGenerator)->load(SignatureBag::class, signatureBagMethods());
    $override = new ReflectionMethod($proxyClass, 'scalarDefaults');

    expect($override->getDeclaringClass()->getName())->toBe($proxyClass) // OVERRIDDEN
        ->and($override->getParameters()[0]->getDefaultValue())->toBe(1)
        ->and($override->getParameters()[1]->getDefaultValue())->toBe('x')
        ->and((string) $override->getReturnType())->toBe('int');
});

it('copies a nullable union param and a nullable return verbatim', function () {
    $proxyClass = (new ProxyClassGenerator)->load(SignatureBag::class, signatureBagMethods());
    $original = new ReflectionMethod(SignatureBag::class, 'nullableUnion');
    $override = new ReflectionMethod($proxyClass, 'nullableUnion');

    expect((string) $override->getParameters()[0]->getType())->toBe((string) $original->getParameters()[0]->getType())
        ->and($override->getParameters()[0]->allowsNull())->toBeTrue()
        ->and($override->getParameters()[0]->getDefaultValue())->toBeNull()
        ->and((string) $override->getReturnType())->toBe((string) $original->getReturnType());
});

it('copies a variadic param', function () {
    $proxyClass = (new ProxyClassGenerator)->load(SignatureBag::class, signatureBagMethods());
    $override = new ReflectionMethod($proxyClass, 'variadic');

    expect($override->getParameters()[0]->isVariadic())->toBeTrue()
        ->and((string) $override->getParameters()[0]->getType())->toBe('string')
        ->and((string) $override->getReturnType())->toBe('array');
});

it('loads a proxy whose void override is LSP-compatible (a stray `return` would fatal on load)', function () {
    // The mere successful load proves the void path emits NO `return`: PHP fatals "A void function must not
    // return a value" at require-time otherwise. persistVoid(): void is the void method under test.
    $proxyClass = (new ProxyClassGenerator)->load(SignatureBag::class, signatureBagMethods());

    expect((string) (new ReflectionMethod($proxyClass, 'persistVoid'))->getReturnType())->toBe('void')
        ->and((new ReflectionMethod($proxyClass, 'persistVoid'))->getDeclaringClass()->getName())->toBe($proxyClass);
});

it('routes a typed-return override through the interceptor and returns its result', function () {
    $proxyClass = (new ProxyClassGenerator)->load(SignatureBag::class, signatureBagMethods());

    /** @var SignatureBag $proxy */
    $proxy = (new ReflectionClass($proxyClass))->newInstanceWithoutConstructor();
    (new ReflectionProperty($proxyClass, '__fireflyTxInterceptor'))
        ->setValue($proxy, new TransactionInterceptor(new TransactionTemplate));

    $count = $proxy->persist('via-proxy'); // forwards the arg to parent, runs in a real tx, returns its result

    expect($count)->toBe(1)->and(DB::table('widgets')->count())->toBe(1);
});

it('routes a void override through the interceptor and commits its side effect', function () {
    $proxyClass = (new ProxyClassGenerator)->load(SignatureBag::class, signatureBagMethods());

    /** @var SignatureBag $proxy */
    $proxy = (new ReflectionClass($proxyClass))->newInstanceWithoutConstructor();
    (new ReflectionProperty($proxyClass, '__fireflyTxInterceptor'))
        ->setValue($proxy, new TransactionInterceptor(new TransactionTemplate));

    $proxy->persistVoid('void-proxy');

    expect(DB::table('widgets')->count())->toBe(1);
});

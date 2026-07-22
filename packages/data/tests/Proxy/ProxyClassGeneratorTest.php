<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Proxy\TxWidget;

/**
 * @return array<string, ProxyMethod>
 */
function proxyFixtureMethods(): array
{
    $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Proxy\\' => __DIR__.'/../Fixtures/Proxy'];

    return (new TransactionalScanner)->scanProxyMethods($psr4)[TxWidget::class];
}

it('generates a loadable proxy that is a subclass of the target', function () {
    $proxyClass = (new ProxyClassGenerator)->load(TxWidget::class, proxyFixtureMethods());

    expect(class_exists($proxyClass))->toBeTrue()
        ->and($proxyClass)->toBe(TxWidget::class.'__FireflyTransactionalProxy')
        ->and(is_subclass_of($proxyClass, TxWidget::class))->toBeTrue();
});

it('copies the transactional method signature verbatim (LSP-compatible override)', function () {
    $proxyClass = (new ProxyClassGenerator)->load(TxWidget::class, proxyFixtureMethods());

    $original = new ReflectionMethod(TxWidget::class, 'increment');
    $override = new ReflectionMethod($proxyClass, 'increment');

    expect($override->getNumberOfParameters())->toBe($original->getNumberOfParameters())
        ->and($override->getParameters()[0]->getName())->toBe('by')
        ->and($override->getParameters()[0]->isDefaultValueAvailable())->toBeTrue()
        ->and($override->getParameters()[0]->getDefaultValue())->toBe(1)
        ->and((string) $override->getReturnType())->toBe((string) $original->getReturnType())
        ->and($override->getDeclaringClass()->getName())->toBe($proxyClass); // it is OVERRIDDEN, not inherited
});

it('does not override non-transactional methods', function () {
    $proxyClass = (new ProxyClassGenerator)->load(TxWidget::class, proxyFixtureMethods());

    // bump() is inherited (declared on the parent), not overridden by the proxy.
    expect((new ReflectionMethod($proxyClass, 'bump'))->getDeclaringClass()->getName())->toBe(TxWidget::class);
});

it('bakes a per-method descriptor into the generated source', function () {
    $source = (new ProxyClassGenerator)->generate(TxWidget::class, proxyFixtureMethods());

    expect($source)->toContain('__FireflyTransactionalProxy extends \\'.TxWidget::class)
        ->and($source)->toContain('__fireflyTxInterceptor')
        ->and($source)->toContain('__fireflyTxDescriptor')
        ->and($source)->toContain('Propagation::REQUIRED');
});

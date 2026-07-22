<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Proxy\InterceptedProbe;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;

/**
 * @return array<string, ProxyMethod>
 */
function interceptedProbeMethods(): array
{
    $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Proxy\\' => __DIR__.'/../Fixtures/Proxy'];

    return (new TransactionalScanner)->scanProxyMethods($psr4)[InterceptedProbe::class];
}

/**
 * A RECORDING spy over the interceptor. Because the generated proxy's `private TransactionInterceptor
 * $__fireflyTxInterceptor` property is typed to the concrete class, the spy MUST be a subclass (hence the
 * interceptor is non-final). run() records the call + descriptor, then invokes proceed() WITHOUT a real
 * transaction — no DB needed. If the override skipped the interceptor wrap and called parent:: directly, run()
 * would never fire and $calls would stay 0.
 *
 * @return TransactionInterceptor&object{calls: int, descriptors: list<TransactionalDescriptor>}
 */
function recordingInterceptor(): TransactionInterceptor
{
    return new class extends TransactionInterceptor
    {
        public int $calls = 0;

        /** @var list<TransactionalDescriptor> */
        public array $descriptors = [];

        public function __construct()
        {
            parent::__construct(new TransactionTemplate);
        }

        public function run(Closure $proceed, TransactionalDescriptor $descriptor): mixed
        {
            $this->calls++;
            $this->descriptors[] = $descriptor;

            return $proceed();
        }
    };
}

it('routes the generated override THROUGH the interceptor (records the call + the right descriptor)', function () {
    $proxyClass = (new ProxyClassGenerator)->load(InterceptedProbe::class, interceptedProbeMethods());

    /** @var InterceptedProbe $proxy */
    $proxy = (new ReflectionClass($proxyClass))->newInstanceWithoutConstructor();

    $spy = recordingInterceptor();
    // Injected exactly the way the (unbuilt T7) ProxyFactory will set the private property.
    (new ReflectionProperty($proxyClass, '__fireflyTxInterceptor'))->setValue($proxy, $spy);

    $result = $proxy->tracked(21);

    expect($spy->calls)->toBe(1) // FAILS if the override calls parent::tracked() directly (interceptor wrap stripped)
        ->and($result)->toBe(42) // proceed() was invoked and its result returned through the interceptor
        ->and($spy->descriptors)->toHaveCount(1)
        ->and($spy->descriptors[0]->propagation)->toBe(Propagation::REQUIRES_NEW) // the RIGHT per-method descriptor
        ->and($spy->descriptors[0]->readOnly)->toBeTrue();
});

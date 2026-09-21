<?php

declare(strict_types=1);

use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Proxy\ProxyPlanCompiler;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Transactional\TransferService;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/** @return array<string,string> */
function transactionalFixturesPsr4(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\Transactional\\' => __DIR__.'/../Fixtures/Transactional'];
}

it('derives a transactional-only plan from a TransactionalManifest', function () {
    $manifest = (new TransactionalScanner)->scan(transactionalFixturesPsr4());

    $plan = ProxyPlan::fromTransactionalManifest($manifest);

    expect($plan->hasProxyFor(TransferService::class))->toBeTrue()
        ->and($plan->proxyClassFor(TransferService::class))->toBe(TransferService::class.ProxyPlan::PROXY_SUFFIX)
        ->and(array_keys($plan->adviceFor(TransferService::class)))->toBe([Advice::TRANSACTIONAL])
        ->and($plan->adviceFor(TransferService::class)[Advice::TRANSACTIONAL]->interceptorClass)->toBe(TransactionInterceptor::class)
        ->and($plan->methodsFor(TransferService::class))->toHaveKey('transfer')
        ->and($plan->methodsFor(TransferService::class)['transfer'][0]['advice'])->toBe(Advice::TRANSACTIONAL)
        ->and($plan->methodsFor(TransferService::class)['transfer'][0]['row'])->toBe($manifest->descriptorFor(TransferService::class, 'transfer')->toArray());
});

it('round-trips through the compiled file and refuses an unknown class', function () {
    $plan = ProxyPlan::fromTransactionalManifest((new TransactionalScanner)->scan(transactionalFixturesPsr4()));
    $path = sys_get_temp_dir().'/firefly-proxy-plan-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new ProxyPlanCompiler)->write($plan, $path);
        $loaded = ProxyPlan::load($path);

        expect($loaded->toArray())->toBe($plan->toArray())
            ->and($loaded->classes())->toBe($plan->classes())
            ->and(fn () => $loaded->proxyClassFor('App\\Nope'))->toThrow(ConfigurationException::class)
            ->and(fn () => ProxyPlan::load($path.'.missing'))->toThrow(ConfigurationException::class);
    } finally {
        @unlink($path);
    }
});

it('names the proxy members an advice id owns', function () {
    $advice = new Advice('audit', TransactionInterceptor::class, stdClass::class, 100);

    expect($advice->property())->toBe('__fireflyAuditInterceptor')
        ->and($advice->factory())->toBe('__fireflyAuditDescriptor')
        ->and(Advice::fromArray($advice->toArray()))->toEqual($advice)
        ->and(fn () => new Advice('Not-An-Id', TransactionInterceptor::class, stdClass::class, 0))->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use Firefly\Data\Proxy\UnsupportedTransactionalMethodException;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\ProxyUnsupported\ByRefService;

/**
 * @return array<string,string>
 */
function proxyUnsupportedPsr4(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\ProxyUnsupported\\' => __DIR__.'/../Fixtures/ProxyUnsupported'];
}

it('fails loud at scan time when a #[Transactional] method declares a by-reference parameter', function () {
    // Without the by-ref detection the scanner would render `&` and return silently — this expectation FAILS.
    expect(fn () => (new TransactionalScanner)->scanProxyMethods(proxyUnsupportedPsr4()))
        ->toThrow(UnsupportedTransactionalMethodException::class);
});

it('names the offending class::method and states the limitation', function () {
    try {
        (new TransactionalScanner)->scanProxyMethods(proxyUnsupportedPsr4());
        throw new RuntimeException('expected UnsupportedTransactionalMethodException, none thrown');
    } catch (UnsupportedTransactionalMethodException $e) {
        expect($e->getMessage())
            ->toContain(ByRefService::class.'::collect')
            ->toContain('by-reference parameters are not supported');
    }
});

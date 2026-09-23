<?php

declare(strict_types=1);

use Firefly\Data\Proxy\UnsupportedTransactionalMethodException;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\ProxyFinalMethod\SealedMethodService;

/**
 * @return array<string,string>
 */
function proxyFinalMethodPsr4(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\ProxyFinalMethod\\' => __DIR__.'/../Fixtures/ProxyFinalMethod'];
}

it('fails loud at scan time when a planned method is final, rather than fatalling inside the generated proxy', function () {
    // Without the is-final guard the plan compiles, the generator renders an override for the final method, and
    // ProxyClassGenerator::load() dies at `require` with "Cannot override final method" — a fatal from inside a
    // generated file, so neither the attribute nor the manifest row it came from is anywhere in the message.
    expect(fn () => (new TransactionalScanner)->scanProxyMethods(proxyFinalMethodPsr4()))
        ->toThrow(UnsupportedTransactionalMethodException::class);
});

it('names the offending class::method and states the limitation', function () {
    try {
        (new TransactionalScanner)->scanProxyMethods(proxyFinalMethodPsr4());
        throw new RuntimeException('expected UnsupportedTransactionalMethodException, none thrown');
    } catch (UnsupportedTransactionalMethodException $e) {
        expect($e->getMessage())
            ->toContain(SealedMethodService::class.'::sealed')
            ->toContain('Cannot override final method')
            ->toContain('Remove `final` from the method');
    }
});

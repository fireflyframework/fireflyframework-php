<?php

declare(strict_types=1);

use Firefly\Data\Proxy\UnsupportedTransactionalMethodException;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\ProxyAncestorFinalMethod\AncestorSealedService;
use Firefly\Data\Tests\Fixtures\ProxyAncestorFinalMethod\VendorBase;
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

/*
 | …and it names WHERE THE `final` IS. A class-level #[Transactional] plans every public method the class
 | exposes, inherited ones included, so the sealed method is routinely declared by a base the reader does not
 | own — the framework's own AutoConfiguration::register() is final. Naming the planned class sent that reader
 | to a file with no `final` in it, with an instruction they could not follow at the location it named.
 |
 | It stays a REFUSAL where observability's metric scan skips the same shape, and deliberately: skipping a
 | meter loses a dashboard line, skipping a transaction boundary runs a #[Transactional] method with no
 | transaction around it and leaves half the writes committed on a mid-method failure. The scanner docblock
 | carries the reasoning; this test pins the message.
 */

/**
 * @return array<string,string>
 */
function proxyAncestorFinalMethodPsr4(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\ProxyAncestorFinalMethod\\' => __DIR__.'/../Fixtures/ProxyAncestorFinalMethod'];
}

it('names the DECLARING class, and the class the plan was keyed by, when the final method is an ancestor\'s', function () {
    try {
        (new TransactionalScanner)->scanProxyMethods(proxyAncestorFinalMethodPsr4());
        throw new RuntimeException('expected UnsupportedTransactionalMethodException, none thrown');
    } catch (UnsupportedTransactionalMethodException $e) {
        expect($e->getMessage())
            ->toContain(VendorBase::class.'::register() (planned via '.AncestorSealedService::class.')')
            ->toContain('The `final` is on '.VendorBase::class)
            ->toContain('Remove `final` from '.VendorBase::class.'::register() if you own it')
            // The two remedies that are the reader's whoever owns the base.
            ->toContain('narrow the attribute to the methods that need a transaction')
            ->toContain('TransactionTemplate')
            // …and never the instruction the old message gave, about a file with no `final` in it.
            ->not->toContain(AncestorSealedService::class.'::register() is final');
    }
});

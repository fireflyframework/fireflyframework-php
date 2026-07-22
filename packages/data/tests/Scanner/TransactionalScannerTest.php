<?php

declare(strict_types=1);

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Transactional\TransferService;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * @return array<string, string>
 */
function transactionalFixtures(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\Transactional\\' => __DIR__.'/../Fixtures/Transactional'];
}

it('emits a proxy entry for a class with transactional methods', function () {
    $manifest = (new TransactionalScanner)->scan(transactionalFixtures());

    expect($manifest)->toBeInstanceOf(TransactionalManifest::class)
        ->and($manifest->hasProxyFor(TransferService::class))->toBeTrue()
        ->and($manifest->proxyClassFor(TransferService::class))
        ->toBe(TransferService::class.'__FireflyTransactionalProxy');
});

it('lets a method-level attribute override the class-level default', function () {
    $manifest = (new TransactionalScanner)->scan(transactionalFixtures());

    $transfer = $manifest->descriptorFor(TransferService::class, 'transfer');
    $balance = $manifest->descriptorFor(TransferService::class, 'balance');

    expect($transfer->propagation)->toBe(Propagation::REQUIRES_NEW)
        ->and($transfer->readOnly)->toBeFalse()           // the method-level attribute REPLACES class-level
        ->and($balance->propagation)->toBe(Propagation::REQUIRED)
        ->and($balance->readOnly)->toBeTrue();            // inherits the class-level default
});

it('captures #[Query] methods into the manifest', function () {
    $manifest = (new TransactionalScanner)->scan(transactionalFixtures());

    expect($manifest->queriesFor(TransferService::class))->toBe([
        'findByName' => ['sql' => 'select * from accounts where name = :name', 'native' => false],
    ]);
});

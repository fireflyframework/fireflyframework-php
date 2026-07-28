<?php

declare(strict_types=1);

namespace Lumen\Tests\Infrastructure;

use Lumen\Domain\Currency;
use Lumen\Domain\Money;
use Lumen\Domain\Wallet;
use Lumen\Infrastructure\WalletRepository;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

it('persists and reloads a wallet through the port', function () {
    /** @var LumenTestCase $this */
    /** @var WalletRepository $repo */
    $repo = $this->fireflyContext()->get(WalletRepository::class);

    $wallet = Wallet::open('wlt-10', 'owner-10', Currency::EUR);
    $wallet->deposit(new Money(2500, Currency::EUR));
    $repo->save($wallet);

    $reloaded = $repo->findById('wlt-10');
    expect($reloaded)->not->toBeNull()
        ->and($reloaded?->balanceMoney()->minorUnits)->toBe(2500)
        ->and($reloaded?->currency())->toBe(Currency::EUR);
})->group('lumen');

it('resolves a derived findByOwnerId query', function () {
    /** @var LumenTestCase $this */
    /** @var WalletRepository $repo */
    $repo = $this->fireflyContext()->get(WalletRepository::class);
    $repo->save(Wallet::open('wlt-11', 'owner-X', Currency::EUR));
    $repo->save(Wallet::open('wlt-12', 'owner-X', Currency::USD));

    expect($repo->findByOwnerId('owner-X'))->toHaveCount(2);
})->group('lumen');

it('returns an empty list for findByOwnerId on an unknown owner', function () {
    /** @var LumenTestCase $this */
    /** @var WalletRepository $repo */
    $repo = $this->fireflyContext()->get(WalletRepository::class);
    $repo->save(Wallet::open('wlt-13', 'owner-Y', Currency::EUR));

    expect($repo->findByOwnerId('owner-unknown'))->toBe([]);
})->group('lumen');

<?php

declare(strict_types=1);

namespace Lumen\Tests\Domain;

use Firefly\Kernel\Exception\Business\ConflictException;
use Lumen\Domain\Currency;
use Lumen\Domain\Event\FundsDeposited;
use Lumen\Domain\Event\FundsWithdrawn;
use Lumen\Domain\Event\WalletOpened;
use Lumen\Domain\Money;
use Lumen\Domain\Wallet;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

it('opens an empty wallet and records WalletOpened', function () {
    $wallet = Wallet::open('wlt-1', 'owner-1', Currency::EUR);

    expect($wallet->balanceMoney()->equals(Money::zero(Currency::EUR)))->toBeTrue();
    [$event] = $wallet->pendingEvents();
    expect($event)->toBeInstanceOf(WalletOpened::class);
})->group('lumen');

it('refuses to open with a blank owner id', function () {
    expect(fn () => Wallet::open('wlt-blank', '  ', Currency::EUR))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('deposits funds and records FundsDeposited', function () {
    $wallet = Wallet::open('wlt-deposit', 'owner-1', Currency::EUR);
    $wallet->clearEvents();

    $wallet->deposit(new Money(500, Currency::EUR));

    expect($wallet->balanceMoney()->equals(new Money(500, Currency::EUR)))->toBeTrue();
    [$event] = $wallet->pendingEvents();
    expect($event)->toBeInstanceOf(FundsDeposited::class);
})->group('lumen');

it('refuses to deposit a non-positive amount', function () {
    $wallet = Wallet::open('wlt-deposit-zero', 'owner-1', Currency::EUR);

    expect(fn () => $wallet->deposit(new Money(0, Currency::EUR)))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('refuses to deposit a mismatched currency', function () {
    $wallet = Wallet::open('wlt-deposit-mismatch', 'owner-1', Currency::EUR);

    expect(fn () => $wallet->deposit(new Money(100, Currency::USD)))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('refuses to overdraw and leaves the balance + events untouched', function () {
    $wallet = Wallet::open('wlt-2', 'owner-2', Currency::EUR);
    $wallet->deposit(new Money(500, Currency::EUR));
    $wallet->clearEvents();

    expect(fn () => $wallet->withdraw(new Money(501, Currency::EUR)))
        ->toThrow(ConflictException::class);

    expect($wallet->balanceMoney()->equals(new Money(500, Currency::EUR)))->toBeTrue();
    expect($wallet->pendingEvents())->toBe([]);
})->group('lumen');

it('records FundsWithdrawn on a valid withdrawal', function () {
    $wallet = Wallet::open('wlt-3', 'owner-3', Currency::EUR);
    $wallet->deposit(new Money(500, Currency::EUR));
    $wallet->clearEvents();

    $wallet->withdraw(new Money(200, Currency::EUR));

    [$event] = $wallet->pendingEvents();
    expect($event)->toBeInstanceOf(FundsWithdrawn::class);
    expect($wallet->balanceMoney()->equals(new Money(300, Currency::EUR)))->toBeTrue();
})->group('lumen');

it('refuses to withdraw a non-positive amount', function () {
    $wallet = Wallet::open('wlt-withdraw-zero', 'owner-1', Currency::EUR);
    $wallet->deposit(new Money(500, Currency::EUR));

    expect(fn () => $wallet->withdraw(new Money(0, Currency::EUR)))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('drains pending events with pullEvents', function () {
    $wallet = Wallet::open('wlt-pull', 'owner-1', Currency::EUR);

    $events = $wallet->pullEvents();

    expect($events)->toHaveCount(1);
    expect($wallet->pendingEvents())->toBe([]);
})->group('lumen');

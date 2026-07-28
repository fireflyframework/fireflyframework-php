<?php

declare(strict_types=1);

namespace Lumen\Tests\Domain;

use Firefly\Kernel\Exception\Business\ConflictException;
use Lumen\Domain\Currency;
use Lumen\Domain\Money;

it('creates a zero amount for a currency', function () {
    $zero = Money::zero(Currency::EUR);

    expect($zero->minorUnits)->toBe(0);
    expect($zero->currency)->toBe(Currency::EUR);
    expect($zero->isPositive())->toBeFalse();
    expect($zero->isNegative())->toBeFalse();
})->group('lumen');

it('adds two amounts in the same currency', function () {
    $sum = (new Money(1000, Currency::EUR))->add(new Money(50, Currency::EUR));

    expect($sum->minorUnits)->toBe(1050);
    expect($sum->currency)->toBe(Currency::EUR);
})->group('lumen');

it('subtracts two amounts in the same currency', function () {
    $diff = (new Money(1000, Currency::EUR))->subtract(new Money(300, Currency::EUR));

    expect($diff->minorUnits)->toBe(700);
});

it('refuses to add mismatched currencies', function () {
    expect(fn () => (new Money(100, Currency::EUR))->add(new Money(100, Currency::USD)))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('refuses to subtract mismatched currencies', function () {
    expect(fn () => (new Money(100, Currency::EUR))->subtract(new Money(100, Currency::USD)))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('reports positive and negative amounts', function () {
    expect((new Money(1, Currency::EUR))->isPositive())->toBeTrue();
    expect((new Money(1, Currency::EUR))->isNegative())->toBeFalse();
    expect((new Money(-1, Currency::EUR))->isNegative())->toBeTrue();
    expect((new Money(-1, Currency::EUR))->isPositive())->toBeFalse();
})->group('lumen');

it('is equal by value via ValueObjectEquality', function () {
    expect((new Money(1050, Currency::EUR))->equals(new Money(1050, Currency::EUR)))->toBeTrue();
    expect((new Money(1050, Currency::EUR))->equals(new Money(1050, Currency::USD)))->toBeFalse();
    expect((new Money(1050, Currency::EUR))->equals(new Money(999, Currency::EUR)))->toBeFalse();
})->group('lumen');

it('renders a locale-independent string representation', function () {
    expect((string) new Money(1050, Currency::EUR))->toBe('10.50 EUR');
    expect((string) new Money(5, Currency::USD))->toBe('0.05 USD');
    expect((string) Money::zero(Currency::GBP))->toBe('0.00 GBP');
})->group('lumen');

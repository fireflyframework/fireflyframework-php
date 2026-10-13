<?php

declare(strict_types=1);

namespace Lumen\Tests\Domain;

use Firefly\Kernel\Exception\Business\ConflictException;
use Lumen\Domain\Currency;
use Lumen\Domain\Loan\Event\LoanGranted;
use Lumen\Domain\Loan\Event\LoanRepaid;
use Lumen\Domain\Loan\Event\LoanSettled;
use Lumen\Domain\Loan\Loan;
use Lumen\Domain\Money;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

it('grants a loan and records LoanGranted', function () {
    $loan = Loan::grant('loan-1', 'wlt-1', new Money(90000, Currency::EUR));

    [$event] = $loan->uncommittedEvents();
    expect($event)->toBeInstanceOf(LoanGranted::class)
        ->and([$loan->walletId(), $loan->principal()->minorUnits, $loan->outstanding()->minorUnits, $loan->version()])
        ->toBe(['wlt-1', 90000, 90000, 1]);
})->group('lumen');

it('refuses a principal that is not positive', function () {
    expect(fn () => Loan::grant('loan-0', 'wlt-0', new Money(0, Currency::EUR)))
        ->toThrow(ConflictException::class);
})->group('lumen');

it('records the repayment that clears the balance together with the settlement', function () {
    $loan = Loan::grant('loan-2', 'wlt-2', new Money(90000, Currency::EUR));
    $loan->repay(new Money(60000, Currency::EUR));
    $loan->repay(new Money(30000, Currency::EUR));

    expect(array_map(static fn (object $event): string => $event::class, $loan->uncommittedEvents()))
        ->toBe([LoanGranted::class, LoanRepaid::class, LoanRepaid::class, LoanSettled::class])
        ->and([$loan->outstanding()->minorUnits, $loan->repaidMinor(), $loan->repayments(), $loan->isSettled()])
        ->toBe([0, 90000, 2, true]);
})->group('lumen');

it('refuses to repay more than is outstanding, in another currency, or once settled', function () {
    $loan = Loan::grant('loan-3', 'wlt-3', new Money(10000, Currency::EUR));

    expect(fn () => $loan->repay(new Money(10001, Currency::EUR)))->toThrow(ConflictException::class)
        ->and(fn () => $loan->repay(new Money(5000, Currency::USD)))->toThrow(ConflictException::class);

    $loan->repay(new Money(10000, Currency::EUR));

    expect(fn () => $loan->repay(new Money(1, Currency::EUR)))->toThrow(ConflictException::class)
        ->and($loan->version())->toBe(3);
})->group('lumen');

it('rebuilds the same state from its history, and from a snapshot of that state', function () {
    $loan = Loan::grant('loan-4', 'wlt-4', new Money(90000, Currency::EUR));
    $loan->repay(new Money(20000, Currency::EUR));
    $history = $loan->uncommittedEvents();

    $replayed = Loan::reconstitute('loan-4', $history);
    $restored = Loan::reconstitute('loan-4', [], 2, $loan->snapshotState());

    expect([$replayed->outstanding()->minorUnits, $replayed->repayments(), $replayed->committedVersion()])->toBe([70000, 1, 2])
        ->and($restored->snapshotState())->toBe($loan->snapshotState())
        ->and($restored->committedVersion())->toBe(2)
        ->and(Loan::snapshotVersion())->toBe(1);
})->group('lumen');

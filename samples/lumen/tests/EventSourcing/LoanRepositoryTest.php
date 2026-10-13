<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Illuminate\Support\Facades\DB;
use Lumen\Domain\Currency;
use Lumen\Domain\Loan\Loan;
use Lumen\Domain\Money;
use Lumen\Infrastructure\Loan\LoanRepository;

uses(LumenEventSourcingTestCase::class);

it('stores a loan as its events and rebuilds it from them', function () {
    /** @var LumenEventSourcingTestCase $this */
    $loans = $this->app()->make(LoanRepository::class);
    $loan = Loan::grant('loan-r1', 'wlt-r1', new Money(90000, Currency::EUR));
    $loan->repay(new Money(20000, Currency::EUR));
    $loans->save($loan);

    $reloaded = $loans->get('loan-r1');

    expect(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'loan-r1')->orderBy('aggregate_version')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid'])
        ->and([$reloaded->outstanding()->minorUnits, $reloaded->repayments(), $reloaded->committedVersion()])->toBe([70000, 1, 2])
        ->and($this->app()->make(SnapshotStore::class)->latest('loan-r1'))->toBeNull();
})->group('lumen');

it('snapshots the loan every third event and replays only the events after the snapshot', function () {
    /** @var LumenEventSourcingTestCase $this */
    $loans = $this->app()->make(LoanRepository::class);
    $loans->save(Loan::grant('loan-r2', 'wlt-r2', new Money(90000, Currency::EUR)));
    foreach ([20000, 20000, 20000] as $amountMinor) {
        $loan = $loans->get('loan-r2');
        $loan->repay(new Money($amountMinor, Currency::EUR));
        $loans->save($loan);
    }

    $snapshot = $this->app()->make(SnapshotStore::class)->latest('loan-r2');
    $replayed = $this->app()->make(EventStore::class)->load('loan-r2', 4)->events;
    $loan = $loans->get('loan-r2');

    expect($snapshot?->version)->toBe(3)
        ->and($snapshot?->state)->toMatchArray(['repaidMinor' => 40000, 'repayments' => 2])
        ->and(array_map(static fn (StoredEvent $event): string => $event->eventType, $replayed))->toBe(['loan.repaid'])
        ->and([$loan->repaidMinor(), $loan->outstanding()->minorUnits, $loan->committedVersion()])->toBe([60000, 30000, 4]);
})->group('lumen');

it('refuses a stale writer with a 409 ConcurrencyException', function () {
    /** @var LumenEventSourcingTestCase $this */
    $loans = $this->app()->make(LoanRepository::class);
    $loans->save(Loan::grant('loan-r3', 'wlt-r3', new Money(90000, Currency::EUR)));
    $first = $loans->get('loan-r3');
    $second = $loans->get('loan-r3');

    $first->repay(new Money(10000, Currency::EUR));
    $loans->save($first);
    $second->repay(new Money(10000, Currency::EUR));

    expect(fn () => $loans->save($second))->toThrow(ConcurrencyException::class)
        ->and($loans->get('loan-r3')->repaidMinor())->toBe(10000);
})->group('lumen');

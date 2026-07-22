<?php

declare(strict_types=1);

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable();
    });

    foreach ([
        ['status' => 'open', 'amount' => 50, 'email' => 'a@x.test'],
        ['status' => 'open', 'amount' => 150, 'email' => 'B@x.test'],   // mixed-case for IgnoreCase
        ['status' => 'open', 'amount' => 250, 'email' => 'c@x.test'],
        ['status' => 'closed', 'amount' => 999, 'email' => 'd@x.test'],
    ] as $row) {
        Record::query()->create($row);
    }
});

function dispatchRepository(): RecordRepository
{
    // Inline-compile the manifest exactly like firefly:cache would, so the #[Query] method routes.
    $manifest = (new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository',
    ]);

    return new RecordRepository($manifest);
}

it('drives a derived query with And + GreaterThan (predicate binding in order)', function () {
    $rows = dispatchRepository()->findByStatusAndAmountGreaterThan('open', 100);

    expect($rows)->toHaveCount(2)
        ->and(array_map(static fn (Record $r): int => $r->amount, $rows))->toBe([150, 250]);
});

it('honours Top{N} + OrderBy Desc', function () {
    $rows = dispatchRepository()->findTop2ByStatusOrderByAmountDesc('open');

    expect($rows)->toHaveCount(2)
        ->and(array_map(static fn (Record $r): int => $r->amount, $rows))->toBe([250, 150]);
});

it('matches case-insensitively for an IgnoreCase predicate', function () {
    $repo = dispatchRepository();

    expect($repo->existsByEmailIgnoreCase('b@x.test'))->toBeTrue()      // stored 'B@x.test'
        ->and($repo->existsByEmailIgnoreCase('nobody@x.test'))->toBeFalse();
});

it('counts and deletes by a derived predicate', function () {
    $repo = dispatchRepository();

    expect($repo->countByStatus('open'))->toBe(3);

    $deleted = $repo->deleteByStatus('closed');
    expect($deleted)->toBe(1)
        ->and($repo->countByStatus('closed'))->toBe(0)
        ->and(Record::query()->count())->toBe(3);
});

it('routes a #[Query] method to raw SQL with positional binding', function () {
    $rows = dispatchRepository()->findByEmailRaw('c@x.test');
    $amount = $rows[0]['amount'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('c@x.test')
        ->and(is_numeric($amount) ? (int) $amount : $amount)->toBe(250);
});

it('throws BadMethodCallException for an unparseable method', function () {
    expect(fn () => dispatchRepository()->__call('frobnicate', []))->toThrow(BadMethodCallException::class);
});

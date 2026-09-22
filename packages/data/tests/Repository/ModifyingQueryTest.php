<?php

declare(strict_types=1);

use Firefly\Data\Repository\Attributes\Modifying;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        ['status' => 'open', 'amount' => 150, 'email' => 'b@x.test'],
        ['status' => 'closed', 'amount' => 250, 'email' => 'c@x.test'],
    ] as $row) {
        Record::query()->create($row);
    }
});

function modifyingRepository(): RecordRepository
{
    return new RecordRepository((new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository',
    ]));
}

it('runs a #[Modifying] #[Query] as a statement and returns the affected-row count, inside a transaction', function () {
    $repo = modifyingRepository();

    $affected = (new TransactionTemplate)->execute(fn (): int => $repo->closeSmall('closed', 100));

    expect($affected)->toBe(1)
        ->and(Record::query()->where('status', 'closed')->count())->toBe(2)
        // every row changes value here, so the count is 3 on every driver (mysql counts CHANGED rows, sqlite MATCHED)
        ->and(DB::transaction(fn (): int => $repo->closeSmall('archived', 1000)))->toBe(3);
});

it('refuses to run a modifying query outside a transaction, and nothing changes', function () {
    $repo = modifyingRepository();

    expect(fn () => $repo->closeSmall('closed', 100))->toThrow(TransactionRequiredException::class)
        ->and(Record::query()->where('status', 'closed')->count())->toBe(1);
});

it('runs a #[Modifying(requiresTransaction: false)] statement outside a transaction', function () {
    $repo = modifyingRepository();

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and($repo->purgeStatus('closed'))->toBe(1)
        ->and($repo->purgeStatus('closed'))->toBe(0)
        ->and(Record::query()->count())->toBe(2);
});

it('accepts clearAutomatically for source compatibility (a documented no-op on Eloquent)', function () {
    $attribute = new Modifying(clearAutomatically: true);

    expect($attribute->clearAutomatically)->toBeTrue()
        ->and($attribute->requiresTransaction)->toBeTrue();
});

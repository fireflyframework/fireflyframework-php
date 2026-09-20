<?php

declare(strict_types=1);

use Firefly\Data\Repository\Locking\OptimisticLockException;
use Firefly\Data\Tests\Fixtures\Repository\VersionedRecord;
use Firefly\Data\Tests\Fixtures\Repository\VersionedRecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Kernel\Exception\Infrastructure\OptimisticLockingFailureException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('versioned_records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->integer('version')->default(0);
    });
});

it('bumps the version on update and rejects a stale write', function () {
    $repo = new VersionedRecordRepository;
    $created = $repo->save(new VersionedRecord(['name' => 'orig']));
    $id = $created->id;

    $a = $repo->findById($id);
    $b = $repo->findById($id);
    assert($a instanceof VersionedRecord && $b instanceof VersionedRecord);

    $a->name = 'first';
    $repo->save($a);

    $reloaded = $repo->findById($id);
    expect($reloaded?->version)->toBe(1);

    $b->name = 'second';
    expect(fn () => $repo->save($b))->toThrow(OptimisticLockException::class);

    expect($repo->findById($id)?->name)->toBe('first'); // the stale write never landed
});

it('is a kernel OptimisticLockingFailureException, so a catch on the family catches it too', function () {
    $repo = new VersionedRecordRepository;
    $created = $repo->save(new VersionedRecord(['name' => 'orig']));

    $a = $repo->findById($created->id);
    $b = $repo->findById($created->id);
    assert($a instanceof VersionedRecord && $b instanceof VersionedRecord);

    $a->name = 'first';
    $repo->save($a);
    $b->name = 'second';

    try {
        $repo->save($b);
        $this->fail('expected the stale write to be rejected');
    } catch (OptimisticLockingFailureException $e) {
        expect($e)->toBeInstanceOf(OptimisticLockException::class)
            ->and($e->errorCode())->toBe('OPTIMISTIC_LOCK')
            ->and($e->httpStatus())->toBe(409);
    }
});

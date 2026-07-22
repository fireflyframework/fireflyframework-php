<?php

declare(strict_types=1);

use Firefly\Data\Repository\Locking\OptimisticLockException;
use Firefly\Data\Tests\Fixtures\Repository\VersionedRecord;
use Firefly\Data\Tests\Fixtures\Repository\VersionedRecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
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

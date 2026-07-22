<?php

declare(strict_types=1);

use Firefly\Data\Tests\Fixtures\Repository\SoftRecord;
use Firefly\Data\Tests\Fixtures\Repository\SoftRecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('soft_records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->softDeletes();
    });
});

it('soft-deletes a row, hides it from findAll, exposes it via includingDeleted, and restores it', function () {
    $repo = new SoftRecordRepository;
    $row = $repo->save(new SoftRecord(['name' => 'keepsake']));
    $id = $row->id;

    $repo->deleteById($id);

    expect($repo->findAll())->toHaveCount(0)
        ->and($repo->findAllIncludingDeleted())->toHaveCount(1);

    $restored = $repo->restore($id);

    expect($restored)->not->toBeNull()
        ->and($repo->findAll())->toHaveCount(1);
});

<?php

declare(strict_types=1);

use Firefly\Data\Repository\Auditing\AuditorAware;
use Firefly\Data\Tests\Fixtures\Repository\AuditedRecord;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('audited_records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->string('created_by')->nullable();
        $table->string('updated_by')->nullable();
    });
});

it('no-ops when no AuditorAware is bound', function () {
    $record = new AuditedRecord(['name' => 'anon']);
    $record->save();

    expect($record->fresh()?->created_by)->toBeNull();
});

it('stamps created_by / updated_by from a bound AuditorAware', function () {
    app()->instance(AuditorAware::class, new class implements AuditorAware
    {
        public function currentAuditor(): string
        {
            return 'user-42';
        }
    });

    $record = new AuditedRecord(['name' => 'known']);
    $record->save();

    expect($record->fresh()?->created_by)->toBe('user-42')
        ->and($record->fresh()?->updated_by)->toBe('user-42');
});

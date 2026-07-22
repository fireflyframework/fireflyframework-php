<?php

declare(strict_types=1);

use Firefly\Domain\Entity;

final class SampleEntity extends Entity {}
final class OtherEntity extends Entity {}

it('is transient when it has no id', function () {
    expect((new SampleEntity)->isTransient())->toBeTrue()
        ->and((new SampleEntity(1))->isTransient())->toBeFalse();
});

it('treats two entities of the same class with equal non-null ids as equal', function () {
    expect((new SampleEntity(7))->equals(new SampleEntity(7)))->toBeTrue()
        ->and((new SampleEntity(7))->equals(new SampleEntity(8)))->toBeFalse();
});

it('treats a transient entity as equal only to itself (identity)', function () {
    $a = new SampleEntity;

    expect($a->equals($a))->toBeTrue()
        ->and($a->equals(new SampleEntity))->toBeFalse();
});

it('never treats different concrete types as equal even with equal ids', function () {
    expect((new SampleEntity(1))->equals(new OtherEntity(1)))->toBeFalse();
});

it('exposes its id', function () {
    expect((new SampleEntity('u-1'))->id())->toBe('u-1');
});

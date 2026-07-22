<?php

declare(strict_types=1);

use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Isolation;
use Firefly\Data\Transaction\Propagation;

it('defaults to REQUIRED / DEFAULT / write / rollback-on-any-throwable', function () {
    $tx = new Transactional;

    expect($tx->propagation)->toBe(Propagation::REQUIRED)
        ->and($tx->isolation)->toBe(Isolation::DEFAULT)
        ->and($tx->readOnly)->toBeFalse()
        ->and($tx->rollbackFor)->toBe([Throwable::class])
        ->and($tx->noRollbackFor)->toBe([])
        ->and($tx->connection)->toBeNull()
        ->and($tx->timeout)->toBeNull();
});

it('carries overridden settings', function () {
    $tx = new Transactional(
        propagation: Propagation::REQUIRES_NEW,
        isolation: Isolation::SERIALIZABLE,
        readOnly: true,
        noRollbackFor: [RuntimeException::class],
        connection: 'reporting',
        timeout: 5,
    );

    expect($tx->propagation)->toBe(Propagation::REQUIRES_NEW)
        ->and($tx->isolation)->toBe(Isolation::SERIALIZABLE)
        ->and($tx->readOnly)->toBeTrue()
        ->and($tx->noRollbackFor)->toBe([RuntimeException::class])
        ->and($tx->connection)->toBe('reporting');
});

it('targets classes and methods and exposes seven propagation modes', function () {
    $attr = (new ReflectionClass(Transactional::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($attr->flags & Attribute::TARGET_CLASS)->toBe(Attribute::TARGET_CLASS)
        ->and($attr->flags & Attribute::TARGET_METHOD)->toBe(Attribute::TARGET_METHOD)
        ->and(Propagation::cases())->toHaveCount(7)
        ->and(Propagation::fromName('NESTED'))->toBe(Propagation::NESTED);
});

it('maps isolation cases to their SQL level strings', function () {
    expect(Isolation::READ_COMMITTED->value)->toBe('READ COMMITTED')
        ->and(Isolation::SERIALIZABLE->value)->toBe('SERIALIZABLE')
        ->and(Isolation::cases())->toHaveCount(5);
});

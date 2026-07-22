<?php

declare(strict_types=1);

use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Isolation;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;

it('is built from an attribute', function () {
    $d = TransactionalDescriptor::fromAttribute(new Transactional(
        propagation: Propagation::REQUIRES_NEW,
        isolation: Isolation::REPEATABLE_READ,
        readOnly: true,
        rollbackFor: [RuntimeException::class],
        noRollbackFor: [LogicException::class],
        connection: 'reporting',
        timeout: 3,
    ));

    expect($d->propagation)->toBe(Propagation::REQUIRES_NEW)
        ->and($d->isolation)->toBe(Isolation::REPEATABLE_READ)
        ->and($d->readOnly)->toBeTrue()
        ->and($d->connection)->toBe('reporting')
        ->and($d->timeout)->toBe(3);
});

it('round-trips through the pure-array manifest row', function () {
    $d = TransactionalDescriptor::fromAttribute(new Transactional(
        propagation: Propagation::NESTED,
        isolation: Isolation::SERIALIZABLE,
        readOnly: true,
        rollbackFor: [RuntimeException::class],
        noRollbackFor: [LogicException::class],
        connection: 'reporting',
        timeout: 7,
    ));

    $row = $d->toArray();

    expect($row)->toBe([
        'propagation' => 'NESTED',
        'isolation' => 'SERIALIZABLE',
        'readOnly' => true,
        'rollbackFor' => [RuntimeException::class],
        'noRollbackFor' => [LogicException::class],
        'connection' => 'reporting',
        'timeout' => 7,
    ])->and(TransactionalDescriptor::fromArray($row))->toEqual($d);
});

it('defaults to REQUIRED rollback-on-any-throwable', function () {
    $d = new TransactionalDescriptor;

    expect($d->propagation)->toBe(Propagation::REQUIRED)
        ->and($d->rollbackFor)->toBe([Throwable::class]);
});

<?php

declare(strict_types=1);

use Firefly\Data\Transaction\Exception\TransactionNotAllowedException;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;

it('is an InfrastructureException with a stable error code (required)', function () {
    $e = new TransactionRequiredException;

    expect($e)->toBeInstanceOf(InfrastructureException::class)
        ->and($e->errorCode())->toBe('TRANSACTION_REQUIRED')
        ->and($e->httpStatus())->toBe(500);
});

it('is an InfrastructureException with a stable error code (not allowed)', function () {
    $e = new TransactionNotAllowedException;

    expect($e)->toBeInstanceOf(InfrastructureException::class)
        ->and($e->errorCode())->toBe('TRANSACTION_NOT_ALLOWED')
        ->and($e->httpStatus())->toBe(500);
});

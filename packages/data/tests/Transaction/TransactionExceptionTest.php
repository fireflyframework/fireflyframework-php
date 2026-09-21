<?php

declare(strict_types=1);

use Firefly\Data\Transaction\Exception\TransactionNotAllowedException;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Firefly\Data\Transaction\Exception\TransactionSystemException;
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

it('is an InfrastructureException carrying both failures (system: a commit that failed after a kept exception)', function () {
    $kept = new RuntimeException('kept by noRollbackFor');
    $commit = new RuntimeException('commit failed');
    $e = new TransactionSystemException($kept, $commit);

    expect($e)->toBeInstanceOf(InfrastructureException::class)
        ->and($e->errorCode())->toBe('TRANSACTION_SYSTEM_ERROR')
        ->and($e->httpStatus())->toBe(500)
        ->and($e->getPrevious())->toBe($commit)
        ->and($e->applicationException)->toBe($kept);
});

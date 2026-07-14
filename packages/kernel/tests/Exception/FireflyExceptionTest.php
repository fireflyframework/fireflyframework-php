<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;

it('is a runtime exception carrying framework error metadata', function () {
    $previous = new RuntimeException('root cause');
    $e = new FireflyException(
        message: 'boom',
        errorCode: 'X_ERROR',
        httpStatus: 418,
        category: ErrorCategory::Infrastructure,
        severity: ErrorSeverity::Critical,
        previous: $previous,
    );

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toBe('boom')
        ->and($e->errorCode())->toBe('X_ERROR')
        ->and($e->httpStatus())->toBe(418)
        ->and($e->category())->toBe(ErrorCategory::Infrastructure)
        ->and($e->severity())->toBe(ErrorSeverity::Critical)
        ->and($e->getPrevious())->toBe($previous);
});

it('defaults to a 500 internal error', function () {
    $e = new FireflyException('unexpected', 'INTERNAL_ERROR');

    expect($e->httpStatus())->toBe(500)
        ->and($e->category())->toBe(ErrorCategory::Internal)
        ->and($e->severity())->toBe(ErrorSeverity::Error);
});
